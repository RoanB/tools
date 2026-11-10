<?php
/**
 * Import the Grottocenter exports into the cave catalogue
 *
 * Feeds the Grottocenter search exports to Topo_Grottocenter_Import.
 * The GeoJSON file is the master dataset (coordinates + entrance
 * id/url); the CSV in the same directory is the supplement that covers
 * the rows the GeoJSON missed. The importer upserts in place, so
 * re-running never duplicates caves.
 *
 * The export files live in util/tmp (as downloaded from Grottocenter);
 * override the directory with --dir=<path> if needed.
 *
 * Usage:
 *   php util/bin/topos_import.php             # preview
 *   php util/bin/topos_import.php --execute   # import for real
 *
 * @author Roan Buysse <roan@buysse.be>
 */

require_once __DIR__ . '/../../lib/base/Bootstrap.php';
Bootstrap::boot();

use Skeleton\Database\Database;

$arguments = $argv;
array_shift($arguments);
$execute = in_array('--execute', $arguments, true);

// Also pass the file paths through for imports straight from another
// directory; unsupported arguments are ignored for now
$directory = __DIR__ . '/../../util/tmp';
foreach ($arguments as $argument) {
	if (str_starts_with($argument, '--dir=') === true) {
		$directory = substr($argument, 6);
	}
}

$geojson_path = null;
$csv_path = null;
foreach (scandir($directory) ?: [] as $entry) {
	if (str_starts_with((string) $entry, 'Grottocenter_search_export_') === false) {
		continue;
	}

	if (str_ends_with((string) $entry, '.geojson') === true) {
		$geojson_path = $directory . '/' . $entry;
	} elseif (str_ends_with((string) $entry, '.csv') === true) {
		$csv_path = $directory . '/' . $entry;
	}
}

if ($geojson_path === null && $csv_path === null) {
	echo 'No Grottocenter_search_export_*.geojson / *.csv found in ' . $directory . PHP_EOL;
	exit(1);
}

// Preview mode still needs one dry parse, so the counters come from the
// importer itself; the import is applied only with --execute. A preview
// of an upsert is reconstructed by counting, not by writing.
if ($execute === true) {
	$result = \Topo_Grottocenter_Import::run($geojson_path ?? '', $csv_path);

	echo 'GeoJSON: ' . ($geojson_path ?? 'not found') . PHP_EOL;
	echo 'CSV: ' . ($csv_path ?? 'not found') . PHP_EOL;
	echo 'Caves imported: ' . $result['imported'] . PHP_EOL;
	echo 'Caves updated: ' . $result['updated'] . PHP_EOL;
	echo 'Rows skipped: ' . $result['skipped'] . PHP_EOL;
	exit(0);
}

// Preview: report what is on disk and how many rows each export holds
echo 'Preview only. Re-run with --execute to import.' . PHP_EOL;
echo 'GeoJSON: ' . ($geojson_path ?? 'not found') . PHP_EOL;
if ($geojson_path !== null) {
	$data = json_decode((string) file_get_contents($geojson_path), true);
	echo '  features: ' . (is_array($data) && isset($data['features']) === true ? count($data['features']) : 'unreadable') . PHP_EOL;
}
echo 'CSV: ' . ($csv_path ?? 'not found') . PHP_EOL;
if ($csv_path !== null) {
	$handle = fopen($csv_path, 'r');
	if ($handle !== false) {
		$rows = 0;
		fgets($handle); // header row
		while (fgets($handle) !== false) {
			$rows++;
		}
		fclose($handle);
		echo '  rows: ' . $rows . PHP_EOL;
	}
}
echo 'Existing caves: ' . (int) Database::get()->get_one('SELECT COUNT(*) FROM topo_cave') . PHP_EOL;
