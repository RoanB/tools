<?php
/**
 * Backfill the rule audit column on historical transactions
 *
 * Rows categorized before `finance_rule_id` existed carry no provenance,
 * so the Matched column on the rules list stays empty. This script
 * simulates the current rule set over every active, non-internal
 * transaction and stamps the winning rule's id — but ONLY when that
 * rule's category equals the category already stored on the row.
 * Categories are never changed: a row whose stored category no longer
 * matches any current rule keeps its NULL link (manual or categorized
 * under older rules).
 *
 * Idempotent: rows that already carry a link are skipped; re-running
 * after a rule edit simply re-stamps the rows the current rule set
 * agrees with.
 *
 * Usage:
 *   php util/bin/backfill_rule_audit.php            # preview
 *   php util/bin/backfill_rule_audit.php --execute  # write
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
$categorizer = new \Bank_Categorizer();

$category_names = [];
foreach (\Finance_Category::get_all_active() as $category) {
	$category_names[(int) $category->id] = $category->name;
}

$ids = $database->get_column(
	'SELECT `id` FROM `bank_transaction`
	WHERE `archived` IS NULL
	AND `internal` = 0
	AND `finance_category_id` IS NOT NULL
	AND `finance_rule_id` IS NULL',
	[]
);

$stamped = 0;
$skipped_manual = 0;
$skipped_drifted = 0;

foreach (\Bank_Transaction::get_by_ids($ids) as $transaction) {
	[$category, $rule] = $categorizer->categorize_with_rule($transaction);

	// The current rules disagree with the stored assignment: the row was
	// categorized manually or under an older rule set — provenance stays
	// empty, the category is never touched
	if ($category === null || (int) $category->id !== (int) $transaction->finance_category_id) {
		$skipped_drifted++;
		continue;
	}

	$stamped++;
	if ($execute === true) {
		$transaction->finance_rule_id = $rule->id;
		$transaction->save();
	}
}

echo 'Transactions considered: ' . count($ids) . PHP_EOL;
echo 'Audit links ' . ($execute === true ? 'stamped' : 'to stamp') . ': ' . $stamped . PHP_EOL;
echo 'No current rule matches (manual or drifted): ' . $skipped_drifted . PHP_EOL;

if ($execute === false) {
	echo PHP_EOL . 'Preview only. Re-run with --execute to write these links.' . PHP_EOL;
	exit(0);
}

echo PHP_EOL . 'Matched counts per rule:' . PHP_EOL;
foreach (\Bank_Rule_Analysis::get_audit_counts() as $rule_id => $count) {
	echo '  #' . $rule_id . ': ' . $count . PHP_EOL;
}
