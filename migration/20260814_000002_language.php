<?php
/**
 * Add magic columns to the language table
 *
 * The initial migration created the language table without the skeleton magic
 * columns. The framework Language model relies on uuid/created/updated, so add
 * them and backfill existing rows. All operations are idempotent so this
 * migration can also run against a schema that already has the columns.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260814_000002_Language extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		if ($this->column_exists('language', 'uuid') === false) {
			$database->query('ALTER TABLE `language` ADD COLUMN `uuid` CHAR(36) DEFAULT NULL AFTER `id`');
		}

		if ($this->column_exists('language', 'created') === false) {
			$database->query('ALTER TABLE `language` ADD COLUMN `created` DATETIME DEFAULT NULL AFTER `name_short`');
		}

		if ($this->column_exists('language', 'updated') === false) {
			$database->query('ALTER TABLE `language` ADD COLUMN `updated` DATETIME DEFAULT NULL AFTER `created`');
		}

		if ($this->column_exists('language', 'archived') === false) {
			$database->query('ALTER TABLE `language` ADD COLUMN `archived` DATETIME DEFAULT NULL AFTER `updated`');
		}

		$database->query("UPDATE `language` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = ''");
		$database->query("UPDATE `language` SET `created` = NOW(), `updated` = NOW() WHERE `created` IS NULL OR `created` < '1970-01-01'");

		if ($this->index_exists('language', 'uuid') === false) {
			$database->query('ALTER TABLE `language` ADD UNIQUE KEY `uuid` (`uuid`)');
		}
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();

		if ($this->index_exists('language', 'uuid') === true) {
			$database->query('ALTER TABLE `language` DROP INDEX `uuid`');
		}

		if ($this->column_exists('language', 'archived') === true) {
			$database->query('ALTER TABLE `language` DROP COLUMN `archived`');
		}

		if ($this->column_exists('language', 'updated') === true) {
			$database->query('ALTER TABLE `language` DROP COLUMN `updated`');
		}

		if ($this->column_exists('language', 'created') === true) {
			$database->query('ALTER TABLE `language` DROP COLUMN `created`');
		}

		if ($this->column_exists('language', 'uuid') === true) {
			$database->query('ALTER TABLE `language` DROP COLUMN `uuid`');
		}
	}

	/**
	 * Check if a column exists on the language table
	 *
	 * @access private
	 * @param string $table
	 * @param string $column
	 * @return bool $exists
	 */
	private function column_exists(string $table, string $column): bool {
		$database = Database::get();
		$database_name = $database->get_one('SELECT DATABASE()');

		$count = $database->get_one(
			'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			[ $database_name, $table, $column ]
		);

		return (int) $count > 0;
	}

	/**
	 * Check if an index exists on the language table
	 *
	 * @access private
	 * @param string $table
	 * @param string $index
	 * @return bool $exists
	 */
	private function index_exists(string $table, string $index): bool {
		$database = Database::get();
		$database_name = $database->get_one('SELECT DATABASE()');

		$count = $database->get_one(
			'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
			[ $database_name, $table, $index ]
		);

		return (int) $count > 0;
	}
}
