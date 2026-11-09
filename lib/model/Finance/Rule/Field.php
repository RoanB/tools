<?php
/**
 * Finance_Rule_Field
 *
 * Links a finance rule to the transaction field(s) it inspects. A rule can
 * match any of several fields at once, so there is one row per selected
 * field.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Finance_Rule_Field {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;

	/**
	 * Get the field values linked to a rule
	 *
	 * @access public
	 * @param int $finance_rule_id
	 * @return array $fields
	 */
	public static function get_fields_for_rule(int $finance_rule_id): array {
		$database = Database::get();
		$columns = $database->get_column(
			'SELECT `field` FROM `finance_rule_field`
			WHERE `archived` IS NULL
			AND `finance_rule_id` = ?
			ORDER BY `id`',
			[ $finance_rule_id ]
		);

		return array_map('strval', $columns);
	}

	/**
	 * Store the field selection for a rule
	 *
	 * Replaces the current selection with the given list of fields, keeping
	 * the ordering of the input.
	 *
	 * @access public
	 * @param Finance_Rule $rule
	 * @param array $fields
	 */
	public static function set_fields_for_rule(\Finance_Rule $rule, array $fields): void {
		$database = Database::get();
		$active = self::get_active_for_rule($rule->id);
		$fields = array_values(array_unique(array_filter($fields)));

		$requested = [];
		foreach ($fields as $index => $field) {
			$requested[$field] = $index;
		}

		$seen = [];
		foreach ($active as $link) {
			if (isset($requested[$link->field]) === false) {
				$link->archive();
				continue;
			}

			$seen[$link->field] = true;
		}

		foreach ($requested as $field => $index) {
			if (isset($seen[$field]) === true) {
				continue;
			}

			$link = new self();
			$link->finance_rule_id = $rule->id;
			$link->field = $field;
			$link->save();
		}
	}

	/**
	 * Archive all field links of a rule
	 *
	 * Called when the rule itself is archived.
	 *
	 * @access public
	 * @param int $finance_rule_id
	 */
	public static function archive_for_rule(int $finance_rule_id): void {
		foreach (self::get_active_for_rule($finance_rule_id) as $link) {
			$link->archive();
		}
	}

	/**
	 * Get the active field links of a rule
	 *
	 * @access private
	 * @param int $finance_rule_id
	 * @return array $links
	 */
	private static function get_active_for_rule(int $finance_rule_id): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `finance_rule_field`
			WHERE `archived` IS NULL
			AND `finance_rule_id` = ?
			ORDER BY `id`',
			[ $finance_rule_id ]
		);

		return self::get_by_ids($ids);
	}
}
