<?php
/**
 * Finance_Rule
 *
 * An auto-link rule that assigns a budget category to a bank transaction.
 * A rule selects which field(s) of the transaction it inspects and how the
 * pattern is matched (exact, contains, fuzzy or regex). Rules are applied
 * strictly from top to bottom in their global sort order (one shared
 * position across all rules, set by drag & drop in the rules list) and
 * only the first matching rule is applied: the rules below it are never
 * tried for a transaction an earlier rule matched.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Finance_Rule {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Match type: the whole field equals the pattern
	 *
	 * @var string $match_exact
	 */
	const MATCH_EXACT = 'exact';

	/**
	 * Match type: the field contains the pattern as a substring
	 *
	 * @var string $match_contains
	 */
	const MATCH_CONTAINS = 'contains';

	/**
	 * Match type: the field contains the pattern, ignoring accents and case
	 *
	 * @var string $match_fuzzy
	 */
	const MATCH_FUZZY = 'fuzzy';

	/**
	 * Match type: the field matches a regular expression
	 *
	 * @var string $match_regex
	 */
	const MATCH_REGEX = 'regex';

	/**
	 * The transaction description
	 *
	 * @var string $field_description
	 */
	const FIELD_DESCRIPTION = 'description';

	/**
	 * The counterparty name
	 *
	 * @var string $field_counterparty_name
	 */
	const FIELD_COUNTERPARTY_NAME = 'counterparty_name';

	/**
	 * The counterparty account
	 *
	 * @var string $field_counterparty_account
	 */
	const FIELD_COUNTERPARTY_ACCOUNT = 'counterparty_account';

	/**
	 * The free communication
	 *
	 * @var string $field_communication
	 */
	const FIELD_COMMUNICATION = 'communication';

	/**
	 * Get all available match types
	 *
	 * @access public
	 * @return array $match_types
	 */
	public static function get_match_types(): array {
		return [
			self::MATCH_EXACT => 'Exact',
			self::MATCH_CONTAINS => 'Contains',
			self::MATCH_FUZZY => 'Fuzzy',
			self::MATCH_REGEX => 'Regex',
		];
	}

	/**
	 * Get all available fields a rule can match against
	 *
	 * @access public
	 * @return array $fields
	 */
	public static function get_fields(): array {
		return [
			self::FIELD_DESCRIPTION => 'Description',
			self::FIELD_COUNTERPARTY_NAME => 'Counterparty name',
			self::FIELD_COUNTERPARTY_ACCOUNT => 'Counterparty account',
			self::FIELD_COMMUNICATION => 'Communication',
		];
	}

	/**
	 * Get all active rules in their global position
	 *
	 * @access public
	 * @return array $rules
	 */
	public static function get_all_active(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `finance_rule`
			WHERE `archived` IS NULL
			ORDER BY `sort_order`, `id`',
			[]
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Get the next global sort order for a new rule
	 *
	 * New rules are appended at the end of the global rule order: they
	 * match last and can be moved up with drag & drop.
	 *
	 * @access public
	 * @return int $sort_order
	 */
	public static function get_next_sort_order(): int {
		$database = Database::get();
		$max = $database->get_one(
			'SELECT MAX(`sort_order`) FROM `finance_rule`
			WHERE `archived` IS NULL',
			[]
		);

		return (int) $max + 1;
	}

	/**
	 * Find an active rule with the same pattern, match type and category
	 *
	 * Used by the handle page's teach loop so assigning a category with
	 * the "create rule" checkbox never creates a duplicate of a rule
	 * that already exists.
	 *
	 * @access public
	 * @param string $pattern
	 * @param string $match_type
	 * @param int $category_id
	 * @return ?Finance_Rule $rule
	 */
	public static function find_duplicate(string $pattern, string $match_type, int $category_id): ?self {
		$database = Database::get();
		$id = $database->get_one(
			'SELECT `id` FROM `finance_rule`
			WHERE `archived` IS NULL
			AND `pattern` = ?
			AND `match_type` = ?
			AND `finance_category_id` = ?
			LIMIT 1',
			[ $pattern, $match_type, $category_id ]
		);

		if ($id === null) {
			return null;
		}

		return self::get_by_id($id);
	}

	/**
	 * The category this rule belongs to
	 *
	 * @access public
	 * @return ?Finance_Category $category
	 */
	public function get_category(): ?Finance_Category {
		if (empty($this->finance_category_id) === true) {
			return null;
		}

		return \Finance_Category::get_by_id($this->finance_category_id);
	}

	/**
	 * The human readable label for the match type
	 *
	 * @access public
	 * @return string $label
	 */
	public function get_match_type_label(): string {
		$labels = self::get_match_types();

		return $labels[$this->match_type] ?? $this->match_type;
	}

	/**
	 * Get the transaction fields this rule inspects
	 *
	 * @access public
	 * @return array $fields
	 */
	public function get_selected_fields(): array {
		return \Finance_Rule_Field::get_fields_for_rule($this->id);
	}

	/**
	 * Set the transaction fields this rule inspects
	 *
	 * @access public
	 * @param array $fields
	 */
	public function set_fields(array $fields): void {
		\Finance_Rule_Field::set_fields_for_rule($this, $fields);
	}

	/**
	 * Archive the rule and its field links
	 *
	 * @access public
	 */
	public function archive(): void {
		$this->archived = date('Y-m-d H:i:s');
		$this->save();
		\Finance_Rule_Field::archive_for_rule($this->id);
	}

	/**
	 * Restore an archived rule
	 *
	 * @access public
	 */
	public function restore(): void {
		$this->archived = null;
		$this->save();
	}
}
