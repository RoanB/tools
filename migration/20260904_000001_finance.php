<?php
/**
 * Finance schema
 *
 * Creates the tables for the finances area: bank accounts, budget
 * categories, auto-link rules and the imported bank transactions.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260904_000001_Finance extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();

		$database->query('
			CREATE TABLE IF NOT EXISTS `bank_account` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`name` VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
				`iban` VARCHAR(34) NOT NULL DEFAULT \'\' COLLATE utf8mb4_unicode_ci,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');

		$database->query('
			CREATE TABLE IF NOT EXISTS `finance_category` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`name` VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
				`color` VARCHAR(32) NOT NULL DEFAULT \'\' COLLATE utf8mb4_unicode_ci,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');

		$database->query('
			CREATE TABLE IF NOT EXISTS `finance_rule` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`finance_category_id` INT(11) UNSIGNED NOT NULL,
				`name` VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
				`match_type` VARCHAR(32) NOT NULL COLLATE utf8mb4_unicode_ci,
				`pattern` TEXT NOT NULL COLLATE utf8mb4_unicode_ci,
				`sort_order` INT(11) UNSIGNED NOT NULL DEFAULT 0,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				KEY `finance_category_id` (`finance_category_id`),
				CONSTRAINT `finance_rule_ibfk_1` FOREIGN KEY (`finance_category_id`) REFERENCES `finance_category` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');

		$database->query('
			CREATE TABLE IF NOT EXISTS `finance_rule_field` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`finance_rule_id` INT(11) UNSIGNED NOT NULL,
				`field` VARCHAR(64) NOT NULL COLLATE utf8mb4_unicode_ci,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				UNIQUE KEY `rule_field` (`finance_rule_id`, `field`),
				KEY `finance_rule_id` (`finance_rule_id`),
				CONSTRAINT `finance_rule_field_ibfk_1` FOREIGN KEY (`finance_rule_id`) REFERENCES `finance_rule` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		');

		$database->query('
			CREATE TABLE IF NOT EXISTS `bank_transaction` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) NOT NULL,
				`bank_account_id` INT(11) UNSIGNED NOT NULL,
				`finance_category_id` INT(11) UNSIGNED DEFAULT NULL,
				`transaction_number` VARCHAR(64) COLLATE utf8mb4_unicode_ci,
				`value_date` DATE DEFAULT NULL,
				`transaction_date` DATE DEFAULT NULL,
				`description` TEXT COLLATE utf8mb4_unicode_ci,
				`counterparty_name` VARCHAR(255) COLLATE utf8mb4_unicode_ci,
				`counterparty_account` VARCHAR(255) COLLATE utf8mb4_unicode_ci,
				`amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				`currency` VARCHAR(8) COLLATE utf8mb4_unicode_ci,
				`balance` DECIMAL(10,2) DEFAULT NULL,
				`communication` TEXT COLLATE utf8mb4_unicode_ci,
				`created` DATETIME NOT NULL,
				`updated` DATETIME NOT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				KEY `bank_account_id` (`bank_account_id`),
				KEY `finance_category_id` (`finance_category_id`),
				UNIQUE KEY `import_key` (`bank_account_id`, `transaction_number`, `value_date`, `amount`, `communication`(191)),
				CONSTRAINT `bank_transaction_ibfk_1` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_account` (`id`),
				CONSTRAINT `bank_transaction_ibfk_2` FOREIGN KEY (`finance_category_id`) REFERENCES `finance_category` (`id`)
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
		$database->query('DROP TABLE IF EXISTS `bank_transaction`');
		$database->query('DROP TABLE IF EXISTS `finance_rule_field`');
		$database->query('DROP TABLE IF EXISTS `finance_rule`');
		$database->query('DROP TABLE IF EXISTS `finance_category`');
		$database->query('DROP TABLE IF EXISTS `bank_account`');
	}
}
