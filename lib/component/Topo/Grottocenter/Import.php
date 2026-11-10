<?php
/**
 * Topo_Grottocenter_Import
 *
 * Seeds and updates the cave catalogue from Grottocenter search
 * exports. The GeoJSON export is the master dataset: every feature
 * carries coordinates and the Grottocenter entrance url, so caves are
 * keyed and upserted by entrance id. The CSV export is a supplement:
 * same catalogue without urls and coordinates, matched to existing
 * caves by (name, location) — it only fills rows the GeoJSON missed.
 *
 * Re-running the import never duplicates: rows update in place (the
 * same dedup philosophy as the bank statement import). Incoming empty
 * values never overwrite a stored value.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Topo_Grottocenter_Import {

	/**
	 * Run the import over one or both export files
	 *
	 * Both formats describe the same catalogue; the GeoJSON wins whenever
	 * a cave exists in both. Returns counters for the result message.
	 *
	 * @access public
	 * @param string $geojson_path
	 * @param ?string $csv_path
	 * @return array $result
	 */
	public static function run(string $geojson_path, ?string $csv_path = null): array {
		$result = [
			'imported' => 0,
			'updated' => 0,
			'skipped' => 0,
		];

		if (file_exists($geojson_path) === true) {
			$geo_result = self::import_geojson($geojson_path, self::load_caves_by_grottocenter_id());
			$result['imported'] += $geo_result['imported'];
			$result['updated'] += $geo_result['updated'];
			$result['skipped'] += $geo_result['skipped'];
		}

		if ($csv_path !== null && $csv_path !== '' && file_exists($csv_path) === true) {
			$csv_result = self::import_csv($csv_path);
			$result['imported'] += $csv_result['imported'];
			$result['updated'] += $csv_result['updated'];
			$result['skipped'] += $csv_result['skipped'];
		}

		return $result;
	}

	/**
	 * Import a Grottocenter GeoJSON export
	 *
	 * One feature per cave, upserted by the entrance id parsed from
	 * grottocenterUrl. Works off an in-memory index of existing
	 * grottocenter ids, so 9,816 features cost one database query to
	 * prepare, not one query per row.
	 *
	 * @access private
	 * @param string $path
	 * @param array $existing Existing caves keyed by grottocenter id
	 * @return array $result
	 */
	private static function import_geojson(string $path, array $existing): array {
		$content = file_get_contents($path);
		$data = json_decode((string) $content, true);

		if (is_array($data) === false || isset($data['features']) === false || is_array($data['features']) === false) {
			throw new \Exception('Grottocenter GeoJSON does not carry a features list');
		}

		$result = [
			'imported' => 0,
			'updated' => 0,
			'skipped' => 0,
		];

		foreach ($data['features'] as $feature) {
			if (is_array($feature) === false || isset($feature['properties']) === false || is_array($feature['properties']) === false) {
				$result['skipped']++;
				continue;
			}

			$properties = $feature['properties'];
			$name = trim((string) ($properties['Name'] ?? ''));
			if ($name === '') {
				$result['skipped']++;
				continue;
			}

			$url = (string) ($properties['grottocenterUrl'] ?? '');
			$grottocenter_id = null;
			if (preg_match('#/entrances/(\d+)#', $url, $matches) === 1) {
				$grottocenter_id = (int) $matches[1];
			}

			$latitude = null;
			$longitude = null;
			if (isset($feature['geometry']['coordinates']) === true
				&& is_array($feature['geometry']['coordinates']) === true
				&& count($feature['geometry']['coordinates']) >= 2) {
				// GeoJSON point coordinates arrive as [longitude, latitude]
				$longitude = (float) $feature['geometry']['coordinates'][0];
				$latitude = (float) $feature['geometry']['coordinates'][1];
			}

			$cave = null;
			if ($grottocenter_id !== null) {
				$cave = $existing[$grottocenter_id] ?? null;
			}

			$isNew = $cave === null;
			if ($isNew === true) {
				$cave = new Topo_Cave();
				$cave->name = $name;
			}

			self::fill($cave, [
				'location' => (string) ($properties['City'] ?? ''),
				'country' => (string) ($properties['Country'] ?? ''),
				'depth_m' => self::parse_int($properties['Depth'] ?? null),
				'length_m' => self::parse_int($properties['Length'] ?? null),
				'data_quality' => self::parse_int($properties['Data quality'] ?? null),
				'latitude' => $latitude,
				'longitude' => $longitude,
				'grottocenter_id' => $grottocenter_id,
				'grottocenter_url' => $url === '' ? null : $url,
			]);

			$cave->save();

			if ($isNew === true) {
				$result['imported']++;
				if ($grottocenter_id !== null) {
					$existing[$grottocenter_id] = $cave;
				}
			} else {
				$result['updated']++;
			}
		}

		return $result;
	}

	/**
	 * Import a Grottocenter CSV export
	 *
	 * The CSV carries no url and no coordinates. Rows are matched to
	 * existing caves by (name, location); a row that matches nothing
	 * creates a cave without grottocenter_id and without coordinates.
	 * Only filling values: empty incoming cells keep the stored ones.
	 *
	 * @access private
	 * @param string $path
	 * @return array $result
	 */
	private static function import_csv(string $path): array {
		$result = [
			'imported' => 0,
			'updated' => 0,
			'skipped' => 0,
		];

		$handle = fopen($path, 'r');
		if ($handle === false) {
			throw new \Exception('Grottocenter CSV could not be read');
		}

		// The export opens with a BOM-carrying header row: strip the BOM,
		// skip the row once the columns are known
		$headers_raw = fgetcsv($handle, 0, ',', '"', '\\');
		if ($headers_raw === false) {
			fclose($handle);

			return $result;
		}

		$headers = [];
		foreach ($headers_raw as $index => $header) {
			$headers[trim((string) $header, "\xEF\xBB\xBF")] = $index;
		}

		$required = [ 'Name', 'City' ];
		foreach ($required as $column) {
			if (isset($headers[$column]) === false) {
				fclose($handle);
				throw new \Exception('Grottocenter CSV misses required column ' . $column);
			}
		}

		while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			$name = trim((string) self::csv_cell($fields, $headers, 'Name', ''));
			if ($name === '') {
				$result['skipped']++;
				continue;
			}

			$location = trim((string) self::csv_cell($fields, $headers, 'City', ''));
			$country = trim((string) self::csv_cell($fields, $headers, 'Country', ''));
			$depth = self::parse_int(self::csv_cell($fields, $headers, 'Depth', null));
			$length = self::parse_int(self::csv_cell($fields, $headers, 'Length', null));
			$quality = self::parse_int(self::csv_cell($fields, $headers, 'Data quality', null));

			$cave = Topo_Cave::get_by_name_location($name, $location);
			if ($cave === null) {
				$cave = self::find_normalized($name, $location);
			}

			$isNew = $cave === null;
			if ($isNew === true) {
				$cave = new Topo_Cave();
				$cave->name = $name;
			}

			self::fill($cave, [
				'location' => $location,
				'country' => $country,
				'depth_m' => $depth,
				'length_m' => $length,
				'data_quality' => $quality,
			]);

			$cave->save();

			if ($isNew === true) {
				$result['imported']++;
			} else {
				$result['updated']++;
			}
		}

		fclose($handle);

		return $result;
	}

	/**
	 * Load every cave that carries a grottocenter id
	 *
	 * @access private
	 * @return array $existing
	 */
	private static function load_caves_by_grottocenter_id(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_cave WHERE grottocenter_id IS NOT NULL'
		);

		if (count($ids) === 0) {
			return [];
		}

		$existing = [];
		foreach (Topo_Cave::get_by_ids($ids) as $cave) {
			$existing[(int) $cave->grottocenter_id] = $cave;
		}

		return $existing;
	}

	/**
	 * Find a cave by normalized name and location
	 *
	 * The fallback for CSV rows whose spelling differs slightly: MySQL's
	 * utf8mb4_unicode_ci collation already ignores case and accents, so
	 * an exact-by-collation (location, then name) lookup covers the
	 * real-world variants without a full-table walk.
	 *
	 * @access private
	 * @param string $name
	 * @param string $location
	 * @return ?Topo_Cave $cave
	 */
	private static function find_normalized(string $name, string $location): ?Topo_Cave {
		if ($location === '') {
			return null;
		}

		$database = Database::get();
		$ids = $database->get_column(
			'SELECT id FROM topo_cave WHERE location = ? ORDER BY id',
			[ $location ]
		);

		if (count($ids) === 0) {
			return null;
		}

		foreach (Topo_Cave::get_by_ids($ids) as $cave) {
			if ((string) $cave->name === $name) {
				return $cave;
			}
		}

		return null;
	}

	/**
	 * Read one column of a CSV row by header name
	 *
	 * Returns the fallback when the column is not part of this export,
	 * so a missing column behaves the same as an empty cell.
	 *
	 * @access private
	 * @param array $fields
	 * @param array $headers
	 * @param string $column
	 * @param mixed $fallback
	 * @return mixed $value
	 */
	private static function csv_cell(array $fields, array $headers, string $column, mixed $fallback): mixed {
		if (isset($headers[$column]) === false) {
			return $fallback;
		}

		return $fields[$headers[$column]] ?? $fallback;
	}

	/**
	 * Fill non-empty incoming values into a cave
	 *
	 * The empty rule: a value that is absent, empty or null in the
	 * export keeps the stored value — an incomplete CSV export can never
	 * wipe richer data brought in earlier by the GeoJSON import.
	 *
	 * @access private
	 * @param Topo_Cave $cave
	 * @param array $values
	 */
	private static function fill(Topo_Cave $cave, array $values): void {
		foreach ($values as $field => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$cave->$field = $value;
		}
	}

	/**
	 * Parse an export cell as an integer
	 *
	 * Blank and non-numeric cells come back null; "0" is kept as a real
	 * zero.
	 *
	 * @access private
	 * @param mixed $value
	 * @return ?int $integer
	 */
	private static function parse_int(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		$value = trim((string) $value);
		if ($value === '' || is_numeric($value) === false) {
			return null;
		}

		return (int) $value;
	}
}
