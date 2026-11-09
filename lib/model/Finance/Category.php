<?php
/**
 * Finance_Category
 *
 * A budget category that bank transactions are assigned to, either
 * automatically through finance rules or manually. Categories only carry a
 * name and an optional color for the interface; the logic lives in the
 * rules linked to a category.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Finance_Category {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Legacy color names from before the browser color picker stored hex
	 * values
	 *
	 * The colors map to the tabler badge palette; grey and dark use the
	 * application palette because tabler has no grey and this application
	 * overrides dark.
	 *
	 * @var array $legacy_colors
	 */
	const LEGACY_COLORS = [
		'blue' => '#066fd1',
		'azure' => '#4299e1',
		'teal' => '#0ca678',
		'cyan' => '#17a2b8',
		'green' => '#2fb344',
		'lime' => '#74b816',
		'yellow' => '#f59f00',
		'orange' => '#f76707',
		'red' => '#d63939',
		'pink' => '#d6336c',
		'purple' => '#ae3ec9',
		'indigo' => '#4263eb',
		'grey' => '#6b737d',
		'dark' => '#2b2f2e',
	];

	/**
	 * The stored color as lowercase hex, or null when it is not valid
	 *
	 * Accepts a hex color from the browser color picker and the legacy
	 * color names, which are translated to their hex equivalent.
	 *
	 * @access public
	 * @return ?string $color
	 */
	public function get_color(): ?string {
		$color = strtolower(trim((string) $this->color));
		if (preg_match('/^#[0-9a-f]{6}$/', $color) === 1) {
			return $color;
		}

		return self::LEGACY_COLORS[$color] ?? null;
	}

	/**
	 * The inline badge style for the stored color
	 *
	 * The text color follows the background luminance so light colors keep
	 * a readable dark text instead of white.
	 *
	 * @access public
	 * @return string $style
	 */
	public function get_badge_style(): string {
		$color = $this->get_color();
		if ($color === null) {
			return '';
		}

		$red = hexdec(substr($color, 1, 2));
		$green = hexdec(substr($color, 3, 2));
		$blue = hexdec(substr($color, 5, 2));
		$luminance = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255;
		$text_color = $luminance > 0.62 ? '#2b2f2e' : '#f9fafb';

		return 'background-color: ' . $color . '; color: ' . $text_color . ';';
	}

	/**
	 * Get all active categories in name order
	 *
	 * The categories are used as dropdown options, so they come back
	 * alphabetically.
	 *
	 * @access public
	 * @return array $categories
	 */
	public static function get_all_active(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `finance_category`
			WHERE `archived` IS NULL
			ORDER BY `name`',
			[]
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Get the rules linked to this category, ordered by sort order
	 *
	 * @access public
	 * @return array $rules
	 */
	public function get_rules(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `finance_rule`
			WHERE `archived` IS NULL
			AND `finance_category_id` = ?
			ORDER BY `sort_order`, `id`',
			[ $this->id ]
		);

		return \Finance_Rule::get_by_ids($ids);
	}

	/**
	 * Get the number of transactions assigned to this category
	 *
	 * @access public
	 * @return int $count
	 */
	public function get_transaction_count(): int {
		$database = Database::get();
		$count = $database->get_one(
			'SELECT COUNT(*) FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `finance_category_id` = ?',
			[ $this->id ]
		);

		return (int) $count;
	}

	/**
	 * Merge this category into a target category
	 *
	 * Every transaction (archived rows included, so a restore stays
	 * consistent) and every active rule of this category moves to the
	 * target; this category is then archived. Runs in a database
	 * transaction so it is all-or-nothing. The global rule order is not
	 * renumbered: only ownership moves.
	 *
	 * @access public
	 * @param Finance_Category $target
	 * @return array $result
	 */
	public function merge_into(Finance_Category $target): array {
		if ((int) $target->id === (int) $this->id) {
			throw new \Exception('A category cannot be merged into itself');
		}

		$database = Database::get();
		$database->transaction_begin();

		try {
			$transactions = (int) $database->get_one(
				'SELECT COUNT(*) FROM `bank_transaction` WHERE `finance_category_id` = ?',
				[ $this->id ]
			);
			$database->query(
				'UPDATE `bank_transaction`
				SET `finance_category_id` = ?
				WHERE `finance_category_id` = ?',
				[ $target->id, $this->id ]
			);

			$rules = (int) $database->get_one(
				'SELECT COUNT(*) FROM `finance_rule`
				WHERE `finance_category_id` = ?
				AND `archived` IS NULL',
				[ $this->id ]
			);
			$database->query(
				'UPDATE `finance_rule`
				SET `finance_category_id` = ?
				WHERE `finance_category_id` = ?
				AND `archived` IS NULL',
				[ $target->id, $this->id ]
			);

			$this->archive();
		} catch (\Exception $e) {
			$database->transaction_rollback();

			throw $e;
		}

		$database->transaction_commit();

		return [
			'transactions' => $transactions,
			'rules' => $rules,
		];
	}
}
