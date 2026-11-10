<?php
/**
 * Topo_Cave
 *
 * One physical cave or cave system: the unit the cave topos are
 * organised by. The catalogue is seeded from Grottocenter search
 * exports — coordinates, depth, length and the Grottocenter entrance
 * id/url are kept so a re-import updates rows in place instead of
 * duplicating them. Manually created caves carry no Grottocenter link.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Topo_Cave {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Archive a cave (soft delete)
	 *
	 * Archived caves disappear from the gallery and the catalogue but
	 * keep their id: file rows that link to them survive untouched.
	 *
	 * @access public
	 */
	public function archive(): void {
		$this->archived = date('Y-m-d H:i:s');
		$this->save();
	}

	/**
	 * Restore an archived cave
	 *
	 * @access public
	 */
	public function restore(): void {
		$this->archived = null;
		$this->save();
	}

	/**
	 * Get a cave by its Grottocenter entrance id
	 *
	 * @access public
	 * @param int $grottocenter_id
	 * @return ?Topo_Cave $cave
	 */
	public static function get_by_grottocenter_id(int $grottocenter_id): ?self {
		$database = Database::get();
		$id = $database->get_one(
			'SELECT id FROM topo_cave WHERE grottocenter_id = ?',
			[ $grottocenter_id ]
		);

		if ($id === null) {
			return null;
		}

		return self::get_by_id((int) $id);
	}

	/**
	 * Get a cave by name and location
	 *
	 * Exact, case-insensitive match on both fields. Used by the
	 * Grottocenter import as fallback for CSV-only rows that carry no
	 * entrance url, and by file matching as strong name signal.
	 *
	 * @access public
	 * @param string $name
	 * @param string $location
	 * @return ?Topo_Cave $cave
	 */
	public static function get_by_name_location(string $name, string $location): ?self {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_cave WHERE name = ? AND location = ? AND archived IS NULL',
			[ $name, $location ]
		);

		if (count($ids) !== 1) {
			return null;
		}

		return self::get_by_id((int) $ids[0]);
	}

	/**
	 * Get the active (non-archived) files of this cave
	 *
	 * @access public
	 * @return array<Topo_File> $files
	 */
	public function get_files(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_file
			WHERE topo_cave_id = ?
			AND archived IS NULL',
			[ $this->id ]
		);

		return Topo_File::get_by_ids($ids);
	}

	/**
	 * The number of active (non-archived) files of this cave
	 *
	 * @access public
	 * @return int $count
	 */
	public function get_file_count(): int {
		$database = Database::get();
		$count = $database->get_one(
			'SELECT COUNT(*) FROM topo_file
			WHERE topo_cave_id = ?
			AND archived IS NULL',
			[ $this->id ]
		);

		return (int) $count;
	}
}
