<?php
/**
 * Delete transactions imported into KBC bank accounts
 *
 * CLI maintenance script. Removes every `bank_transaction` row whose bank
 * account is a KBC account (identified by the account name). Run it without
 * arguments to preview what would be deleted, then add `--execute` to
 * actually delete the rows.
 *
 * Usage:
 *   php -d extension=mysqli util/bin/cleanup_kbc.php            # preview
 *   php -d extension=mysqli util/bin/cleanup_kbc.php --execute  # delete
 *
 * @author Roan Buysse <roan@buysse.be>
 */

require_once __DIR__ . '/../lib/base/Bootstrap.php';
Bootstrap::boot();

$arguments = $argv;
array_shift($arguments);
$execute = in_array('--execute', $arguments, true);

$database = \Skeleton\Database\Database::get();

$accounts = $database->get_all(
	'SELECT `id`, `name`, `iban`
	FROM `bank_account`
	WHERE `archived` IS NULL
	AND LOWER(`name`) LIKE ?',
	[ '%kbc%' ]
);

echo 'KBC accounts found: ' . count($accounts) . PHP_EOL;

$total = 0;
foreach ($accounts as $account) {
	$count = (int) $database->get_one(
		'SELECT COUNT(*) FROM `bank_transaction`
		WHERE `bank_account_id` = ?',
		[ $account['id'] ]
	);

	if ($count === 0) {
		echo '  - ' . $account['name'] . ' (#' . $account['id'] . '): 0 transactions, nothing to do' . PHP_EOL;
		continue;
	}

	echo '  - ' . $account['name'] . ' (#' . $account['id'] . '): ' . $count . ' transaction(s)' . PHP_EOL;
	$total += $count;
}

echo PHP_EOL . 'Total transactions to delete: ' . $total . PHP_EOL;

if ($execute === false) {
	echo PHP_EOL . 'Preview only. Re-run with --execute to delete these rows.' . PHP_EOL;

	exit(0);
}

if ($total === 0) {
	exit(0);
}

echo 'Deleting... ';
$database->query(
	'DELETE FROM `bank_transaction`
	WHERE `bank_account_id` IN (SELECT `id` FROM `bank_account` WHERE LOWER(`name`) LIKE ?)',
	[ '%kbc%' ]
);
echo 'done.' . PHP_EOL;
