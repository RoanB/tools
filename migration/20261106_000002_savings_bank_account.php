<?php
/**
 * Add the savings flag to bank accounts
 *
 * Savings accounts hold no imported statements: files are never uploaded
 * for them. They only exist so that transactions moving money towards
 * them are recognized as internal transfers — their IBAN participates in
 * the internal-transfer check while the account stays out of the import
 * target and filter lists.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20261106_000002_Savings_Bank_Account extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_account`
			ADD COLUMN `savings` TINYINT(1) NOT NULL DEFAULT 0 AFTER `iban`
		');
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_account`
			DROP COLUMN `savings`
		');
	}
}
