<?php
/**
 * Add the rule audit link to bank transactions
 *
 * Stores which finance rule assigned a transaction's category, so
 * categorizations can be explained after the fact ("why is this in
 * X?"), per-rule match counts can be shown and the conflict report can
 * cross-check the simulation against reality. Manual assignments store
 * NULL. Nullable by design: rows imported before this column existed
 * have no provenance until a re-index touches them.
 *
 * The statements are guarded so the migration can be re-run safely
 * after a partial application (the column add and the FK constraint
 * are separate DDL statements, and MySQL commits DDL implicitly).
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20261109_000001_Transaction_Rule_Audit extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$columns = $database->get_all(
			"SELECT COLUMN_TYPE FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'bank_transaction'
			AND COLUMN_NAME = 'finance_rule_id'",
			[]
		);

		if (count($columns) === 0) {
			$database->query('
				ALTER TABLE `bank_transaction`
				ADD COLUMN `finance_rule_id` INT(11) UNSIGNED NULL AFTER `finance_category_id`
			');
		} elseif (str_contains((string) $columns[0]['COLUMN_TYPE'], 'unsigned') === false) {
			// Re-create the column as unsigned: finance_rule.id is
			// INT(11) UNSIGNED and the FK needs matching types
			$database->query('
				ALTER TABLE `bank_transaction`
				MODIFY COLUMN `finance_rule_id` INT(11) UNSIGNED NULL AFTER `finance_category_id`
			');
		}

		$constraints = $database->get_all(
			"SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'bank_transaction'
			AND CONSTRAINT_NAME = 'bank_transaction_ibfk_3'",
			[]
		);

		if (count($constraints) === 0) {
			$database->query('
				ALTER TABLE `bank_transaction`
				ADD CONSTRAINT `bank_transaction_ibfk_3`
				FOREIGN KEY (`finance_rule_id`) REFERENCES `finance_rule` (`id`)
			');
		}
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_transaction`
			DROP FOREIGN KEY `bank_transaction_ibfk_3`
		');
		$database->query('
			ALTER TABLE `bank_transaction`
			DROP COLUMN `finance_rule_id`
		');
	}
}
