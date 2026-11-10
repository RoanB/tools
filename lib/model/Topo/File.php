<?php
/**
 * Topo_File
 *
 * One file of the NAS topos folder, indexed in place: the tool stores
 * only metadata (path relative to the NAS root, size, mime type, sha256,
 * NAS modification timestamp) and streams the original from the NAS on
 * demand. relative_path is the stable identity — the unique key that
 * makes an incremental scan cheap and safe. topo_cave_id links a file to
 * its cave; files link without a cave stay in the review queue until
 * matched or assigned by hand.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Topo_File {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Archive a file row (soft delete)
	 *
	 * @access public
	 */
	public function archive(): void {
		$this->archived = date('Y-m-d H:i:s');
		$this->save();
	}

	/**
	 * Restore an archived file row
	 *
	 * @access public
	 */
	public function restore(): void {
		$this->archived = null;
		$this->save();
	}

	/**
	 * Get a file by its path relative to the NAS root
	 *
	 * @access public
	 * @param string $relative_path
	 * @return ?Topo_File $file
	 */
	public static function get_by_relative_path(string $relative_path): ?self {
		$database = Database::get();
		$id = $database->get_one(
			'SELECT id FROM topo_file WHERE relative_path = ?',
			[ $relative_path ]
		);

		if ($id === null) {
			return null;
		}

		return self::get_by_id((int) $id);
	}

	/**
	 * Get all unassigned active files, oldest first
	 *
	 * The review queue: files the scanner could not match to a cave.
	 *
	 * @access public
	 * @return array<Topo_File> $files
	 */
	public static function get_unassigned(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_file
			WHERE archived IS NULL
			AND topo_cave_id IS NULL
			ORDER BY id'
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Get the cave this file belongs to
	 *
	 * @access public
	 * @return ?Topo_Cave $cave
	 */
	public function get_cave(): ?Topo_Cave {
		if ($this->topo_cave_id === null) {
			return null;
		}

		try {
			return Topo_Cave::get_by_id((int) $this->topo_cave_id);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get the active files of one cave, filename order
	 *
	 * @access public
	 * @param int $topo_cave_id
	 * @return array<Topo_File> $files
	 */
	public static function get_by_cave(int $topo_cave_id): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_file
			WHERE topo_cave_id = ?
			AND archived IS NULL
			ORDER BY filename',
			[ $topo_cave_id ]
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Count the active files in the index
	 *
	 * @access public
	 * @return int $count
	 */
	public static function count_active(): int {
		$database = Database::get();
		$count = $database->get_one('SELECT COUNT(*) FROM topo_file WHERE archived IS NULL');

		return (int) $count;
	}
}
