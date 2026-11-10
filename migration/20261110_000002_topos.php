<?php
/**
 * Create the topos tables
 *
 * The cave survey ("topo") library: files stay on the Synology NAS, the
 * tool stores only metadata. topo_cave holds the cave catalogue, seeded
 * from Grottocenter search exports (coordinates, depth, length and the
 * entrance id/url kept so a re-import updates in place); topo_file holds
 * the NAS file index, keyed by the file's path relative to the configured
 * NAS root folder so a scan never duplicates files. A cave link (FK) is
 * optional: unlinked files sit in the review queue.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20261110_000002_Topos extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$database->query('
			CREATE TABLE `topo_cave` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) DEFAULT NULL,
				`grottocenter_id` INT(11) UNSIGNED DEFAULT NULL,
				`name` VARCHAR(255) NOT NULL,
				`location` VARCHAR(255) NOT NULL DEFAULT \'\',
				`country` VARCHAR(100) NOT NULL DEFAULT \'\',
				`latitude` DECIMAL(10,6) DEFAULT NULL,
				`longitude` DECIMAL(10,6) DEFAULT NULL,
				`depth_m` INT(11) UNSIGNED DEFAULT NULL,
				`length_m` INT(11) UNSIGNED DEFAULT NULL,
				`data_quality` INT(11) DEFAULT NULL,
				`grottocenter_url` VARCHAR(255) DEFAULT NULL,
				`notes` MEDIUMTEXT DEFAULT NULL,
				`created` DATETIME NOT NULL,
				`updated` DATETIME DEFAULT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				UNIQUE KEY `grottocenter_id` (`grottocenter_id`),
				KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		');

		$database->query('
			CREATE TABLE `topo_file` (
				`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
				`uuid` CHAR(36) DEFAULT NULL,
				`topo_cave_id` INT(11) UNSIGNED DEFAULT NULL,
				`relative_path` VARCHAR(255) NOT NULL,
				`filename` VARCHAR(255) NOT NULL,
				`mime_type` VARCHAR(100) NOT NULL DEFAULT "application/octet-stream",
				`size_bytes` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				`sha256` CHAR(64) DEFAULT NULL,
				`nas_modified` DATETIME DEFAULT NULL,
				`created` DATETIME NOT NULL,
				`updated` DATETIME DEFAULT NULL,
				`archived` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uuid` (`uuid`),
				UNIQUE KEY `relative_path` (`relative_path`),
				KEY `topo_cave_id` (`topo_cave_id`),
				KEY `sha256` (`sha256`),
				CONSTRAINT `topo_file_ibfk_1` FOREIGN KEY (`topo_cave_id`) REFERENCES `topo_cave` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		');
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('DROP TABLE `topo_file`');
		$database->query('DROP TABLE `topo_cave`');
	}
}
