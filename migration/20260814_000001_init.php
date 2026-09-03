<?php
/**
 * Initial schema
 *
 * Creates the language and user tables for the boilerplate.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260814_000001_Init extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$database->query('
			CREATE TABLE IF NOT EXISTS `language` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(64) NOT NULL COLLATE utf8mb4_unicode_ci,
				`name_short` VARCHAR(8) NOT NULL COLLATE utf8mb4_unicode_ci,
				PRIMARY KEY (`id`),
				UNIQUE KEY `name_short` (`name_short`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');

		$database->query('INSERT INTO `language` (`name`, `name_short`) VALUES (\'English\', \'en\')');
		$database->query('INSERT INTO `language` (`name`, `name_short`) VALUES (\'Nederlands\', \'nl\')');

		$database->query('
			CREATE TABLE IF NOT EXISTS `user` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`email` VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
				`password` VARCHAR(255) NOT NULL,
				`firstname` VARCHAR(255) NOT NULL DEFAULT \'\' COLLATE utf8mb4_unicode_ci,
				`lastname` VARCHAR(255) NOT NULL DEFAULT \'\' COLLATE utf8mb4_unicode_ci,
				`admin` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				UNIQUE KEY `email` (`email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('DROP TABLE IF EXISTS `user`');
		$database->query('DROP TABLE IF EXISTS `language`');
	}
}
