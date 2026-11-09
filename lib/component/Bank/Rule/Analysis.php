<?php
/**
 * Bank_Rule_Analysis
 *
 * Read-only simulation of the rule set over the stored transactions.
 * The categorization applies only the first matching rule, so rules
 * below a matching rule never fire: this component reports which rules
 * actually win, which never win (dead rules) and which rules shadow
 * each other (an upper rule claiming transactions that a lower rule
 * also matches). It writes nothing.
 *
 * The sweep builds one Bank_Categorizer and reuses its matching logic
 * for every transaction, exactly like the import and the re-index do.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Bank_Rule_Analysis {

	/**
	 * Analyze the active rule set against the stored transactions
	 *
	 * Every active, non-internal transaction is matched against every
	 * active rule. Returns the per-rule first-match (win) counts, the
	 * ids of dead rules (no wins), the number of transactions matching
	 * more than one rule and the shadow pairs (winning category ->
	 * shadowed category, sorted by count descending).
	 *
	 * @access public
	 * @return array $report
	 */
	public static function analyze(): array {
		$categorizer = new \Bank_Categorizer();
		$database = Database::get();

		$category_names = [];
		foreach (\Finance_Category::get_all_active() as $category) {
			$category_names[(int) $category->id] = $category->name;
		}

		$wins = [];
		$dead_rule_ids = [];
		foreach (\Finance_Rule::get_all_active() as $rule) {
			$wins[(int) $rule->id] = 0;
			$dead_rule_ids[] = (int) $rule->id;
		}

		$shadow_pairs = [];
		$transactions = 0;
		$multi_matched = 0;

		$ids = $database->get_column(
			'SELECT `id` FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `internal` = 0',
			[]
		);

		foreach (\Bank_Transaction::get_by_ids($ids) as $transaction) {
			$transactions++;
			$matching = $categorizer->get_matching_rules($transaction);
			if (count($matching) === 0) {
				continue;
			}

			$winner = array_shift($matching);
			$wins[(int) $winner->id]++;

			$dead_rule_id_key = array_search((int) $winner->id, $dead_rule_ids, true);
			if ($dead_rule_id_key !== false) {
				unset($dead_rule_ids[$dead_rule_id_key]);
			}

			if (count($matching) > 0) {
				$multi_matched++;

				$winner_name = $winner->finance_category_id === null
					? '(no category)'
					: ($category_names[(int) $winner->finance_category_id] ?? '(unknown)');
				foreach ($matching as $shadowed) {
					$shadowed_name = $shadowed->finance_category_id === null
						? '(no category)'
						: ($category_names[(int) $shadowed->finance_category_id] ?? '(unknown)');

					$key = $winner_name . ' → ' . $shadowed_name;
					$shadow_pairs[$key] = ($shadow_pairs[$key] ?? 0) + 1;
				}
			}
		}

		arsort($shadow_pairs);

		return [
			'transactions' => $transactions,
			'multi_matched' => $multi_matched,
			'wins' => $wins,
			'dead_rule_ids' => array_values($dead_rule_ids),
			'shadow_pairs' => $shadow_pairs,
		];
	}

	/**
	 * Get the number of transactions carrying each rule's audit link
	 *
	 * One aggregate query over the stored transactions; rules absent
	 * from the result have zero links. Rows imported before the audit
	 * column existed carry no link, so counts start growing from the
	 * moment the column is populated (import / re-index).
	 *
	 * @access public
	 * @return array $counts
	 */
	public static function get_audit_counts(): array {
		$database = Database::get();
		$rows = $database->get_all(
			'SELECT `finance_rule_id`, COUNT(*) AS `count`
			FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `finance_rule_id` IS NOT NULL
			GROUP BY `finance_rule_id`',
			[]
		);

		$counts = [];
		foreach ($rows as $row) {
			$counts[(int) $row['finance_rule_id']] = (int) $row['count'];
		}

		return $counts;
	}
}
