<?php
/**
 * Make the internal transfer flag non-nullable
 *
 * The pager cannot express "internal IS NULL OR internal = 0" as a
 * condition (same-field conditions are ANDed, and its IN-with-null
 * rendering is broken), so the flag loses its third "undetermined" state:
 * every transaction is either internal (1) or not (0). The pager
 * conditions become plain equality comparisons, which render valid SQL.
 *
 * Undetermined rows are normalized to 0. The down() migration cannot
 * restore which rows were undetermined.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260904_000005_Internal_Not_Null extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$database->query('
			UPDATE `bank_transaction`
			SET `internal` = 0
			WHERE `internal` IS NULL
		');

		$database->query('
			ALTER TABLE `bank_transaction`
			MODIFY `internal` TINYINT(1) NOT NULL DEFAULT 0
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
			MODIFY `internal` TINYINT(1) DEFAULT NULL
		');
	}
}
