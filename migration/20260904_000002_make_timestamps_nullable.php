<?php
/**
 * Make timestamps nullable
 *
 * Makes the `updated` and `archived` columns on the finance tables nullable
 * with a default value, so rows created manually (or by imports) don't have
 * to supply them and no timestamp is fabricated on partial writes.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260904_000002_Make_Timestamps_Nullable extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$database->query('ALTER TABLE `bank_account` MODIFY COLUMN `updated` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `bank_account` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_category` MODIFY COLUMN `updated` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_category` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule` MODIFY COLUMN `updated` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule_field` MODIFY COLUMN `updated` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule_field` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `bank_transaction` MODIFY COLUMN `updated` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `bank_transaction` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
	}

	/**
	 * Down
	 *
	 * Restores the original non-null / nullable definitions.
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();

		$database->query('ALTER TABLE `bank_account` MODIFY COLUMN `updated` DATETIME NOT NULL');
		$database->query('ALTER TABLE `bank_account` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_category` MODIFY COLUMN `updated` DATETIME NOT NULL');
		$database->query('ALTER TABLE `finance_category` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule` MODIFY COLUMN `updated` DATETIME NOT NULL');
		$database->query('ALTER TABLE `finance_rule` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `finance_rule_field` MODIFY COLUMN `updated` DATETIME NOT NULL');
		$database->query('ALTER TABLE `finance_rule_field` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
		$database->query('ALTER TABLE `bank_transaction` MODIFY COLUMN `updated` DATETIME NOT NULL');
		$database->query('ALTER TABLE `bank_transaction` MODIFY COLUMN `archived` DATETIME DEFAULT NULL');
	}
}
