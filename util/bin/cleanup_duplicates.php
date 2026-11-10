<?php
/**
 * Find and archive duplicate transactions
 *
 * A bank that changes its export format (zero-padded extract numbers,
 * reformatted description texts) defeats the import dedup: a re-imported
 * statement is then stored twice. This script finds rows that have an
 * older twin — same bank account, value date and amount, imported in a
 * different batch (a different created timestamp) — and archives the
 * newer twin. Soft delete: archived rows are restorable in the
 * transactions archive.
 *
 * Groups are matched on bank_account_id + value_date + amount. Within a
 * group, rows imported in the same batch as their neighbours are kept
 * (two legitimate identical payments on one day); only rows whose group
 * already holds a row from an older batch are archived. The full pair
 * list is printed for audit.
 *
 * Usage:
 *   php util/bin/cleanup_duplicates.php            # preview
 *   php util/bin/cleanup_duplicates.php --execute  # archive
 *
 * @author Roan Buysse <roan@buysse.be>
 */

require_once __DIR__ . '/../../lib/base/Bootstrap.php';
Bootstrap::boot();

use Skeleton\Database\Database;

$arguments = $argv;
array_shift($arguments);
$execute = in_array('--execute', $arguments, true);

$database = Database::get();

$rows = $database->get_all(
	'SELECT `id`, `bank_account_id`, `value_date`, `amount`, `created`, `transaction_number`, `description`
	FROM `bank_transaction`
	WHERE `archived` IS NULL
	ORDER BY `created`, `id`',
	[]
);

// group by account + date + amount
$groups = [];
foreach ($rows as $row) {
	$key = $row['bank_account_id'] . '|' . $row['value_date'] . '|' . $row['amount'];
	$groups[$key][] = $row;
}

$archived = 0;
foreach ($groups as $group) {
	if (count($group) < 2) {
		continue;
	}

	$kept_batches = [];
	foreach ($group as $row) {
		$batch = substr($row['created'], 0, 19);

		$is_twin = false;
		foreach ($kept_batches as $kept_batch) {
			if ($kept_batch !== $batch) {
				$is_twin = true;
				break;
			}
		}

		if ($is_twin === true) {
			echo 'ARCHIVE #' . $row['id'] . ' (twin of an older batch) acc=' . $row['bank_account_id']
				. ' ' . $row['value_date'] . ' ' . $row['amount']
				. ' [' . $row['transaction_number'] . '] ' . mb_substr((string) $row['description'], 0, 50) . PHP_EOL;
			if ($execute === true) {
				$transaction = \Bank_Transaction::get_by_id((int) $row['id']);
				$transaction->archive();
			}
			$archived++;
			continue;
		}

		$kept_batches[] = $batch;
	}
}

echo PHP_EOL . ($execute === true ? 'Archived' : 'Would archive') . ': ' . $archived . ' rows.' . PHP_EOL;
if ($execute === false) {
	echo 'Preview only. Re-run with --execute to archive these rows (soft delete, restorable).' . PHP_EOL;
}
