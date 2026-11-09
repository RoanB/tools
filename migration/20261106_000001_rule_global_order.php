<?php
/**
 * Make the finance rule order global
 *
 * sort_order was a per-category position: rules of different categories
 * shared the same values and the global matching order fell back to the
 * rule id, which the user cannot control. sort_order becomes a single
 * global position across all rules, maintained by drag & drop in the
 * rules list: the first rule of the list is the only rule tried for a
 * transaction it matches.
 *
 * The existing rules are renumbered 1..N in a logical order: grouped per
 * category, then by their previous position inside that category.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20261106_000001_Rule_Global_Order extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `finance_rule`
			WHERE `archived` IS NULL
			ORDER BY `finance_category_id`, `sort_order`, `id`',
			[]
		);

		$position = 1;
		foreach ($ids as $id) {
			$database->query('UPDATE `finance_rule` SET `sort_order` = ? WHERE `id` = ?', [ $position, $id ]);
			$position++;
		}
	}

	/**
	 * Down
	 *
	 * The original per-category values cannot be restored.
	 *
	 * @access public
	 */
	public function down(): void {
	}
}
