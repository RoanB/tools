<?php
/**
 * Password reset token
 *
 * Creates the user_password_reset_token table for secure password reset flow.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260903_000001_Password_Reset_Token extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$database->query('
			CREATE TABLE `user_password_reset_token` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NULL,
				`user_id` INT(11) UNSIGNED NOT NULL,
				`token` VARCHAR(64) NOT NULL COLLATE utf8mb4_unicode_ci,
				`expires_at` DATETIME NOT NULL,
				`used` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				`created` DATETIME NOT NULL,
				`updated` DATETIME DEFAULT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				UNIQUE KEY `token` (`token`),
				FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
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
		$database->query('DROP TABLE IF EXISTS `user_password_reset_token`');
	}
}
