<?php
/**
 * Add incoming flag to bank_transaction
 *
 * Adds a boolean `incoming` column to flag uncategorized transactions as
 * incoming or outgoing during manual review. Existing uncategorized
 * transactions are backfilled from their amount sign.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260904_000003_Add_Incoming_Flag extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_transaction`
			ADD COLUMN `incoming` TINYINT(1) DEFAULT NULL AFTER `communication`
		');

		$database->query('
			UPDATE `bank_transaction`
			SET `incoming` = CASE WHEN `amount` > 0 THEN 1 ELSE 0 END
			WHERE `finance_category_id` IS NULL
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
			ALTER TABLE `bank_transaction`
			DROP COLUMN `incoming`
		');
	}
}
