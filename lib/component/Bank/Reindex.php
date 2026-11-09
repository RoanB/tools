<?php
/**
 * Bank_Reindex
 *
 * Re-applies the current rule set and bank account list to the stored
 * transactions. Runs the two sweeps of the handle page's Re-index button:
 *
 * - Every not-yet-internal transaction is re-checked against the current
 *   bank account list, so transfers matched after their account was
 *   configured get the internal flag and leave the review queue.
 * - Every uncategorized, non-internal transaction is re-matched against
 *   the current rules in their global order. A transaction that now
 *   matches gets its category and its incoming flag is cleared; the
 *   others keep (or re-receive) the flag from the amount sign.
 *
 * Categorized transactions are skipped unless an include-categorized
 * sweep is requested. Internal flags are never removed: manual "between my
 * own accounts" marks always survive. Only transactions whose assignment
 * actually changes are saved, and each matching pass builds one
 * categorizer that is reused for every transaction.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

class Bank_Reindex {

	/**
	 * Result key: transactions that received a category
	 *
	 * @var string $result_reindexed
	 */
	const RESULT_REINDEXED = 'reindexed';

	/**
	 * Result key: transactions flagged as internal transfer
	 *
	 * @var string $result_transfers
	 */
	const RESULT_TRANSFERS = 'transfers';

	/**
	 * Result key: transactions still in the review queue
	 *
	 * @var string $result_uncategorized
	 */
	const RESULT_UNCATEGORIZED = 'uncategorized';

	/**
	 * Run the re-index and return the resulting counts
	 *
	 * The default sweep touches only uncategorized transactions; add the
	 * include-categorized flag to re-judge every non-internal transaction
	 * against the current rules, overwriting manual assignments and,
	 * when a rule no longer matches, clearing the category so the
	 * transaction re-enters the handle queue.
	 *
	 * @access public
	 * @param bool $include_categorized
	 * @return array $result
	 */
	public static function run(bool $include_categorized = false): array {
		$categorizer = new \Bank_Categorizer();
		$bank_accounts = \Bank_Account::get_all_active();
		$database = \Skeleton\Database\Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `internal` = 0',
			[]
		);

		$reindexed = 0;
		$transfers = 0;
		foreach (\Bank_Transaction::get_by_ids($ids) as $transaction) {
			if ($transaction->is_internal_transfer($bank_accounts) === true) {
				$transaction->internal = 1;
				$transaction->save();
				$transfers++;
				continue;
			}

			if ($include_categorized === false && $transaction->finance_category_id !== null) {
				continue;
			}

			$previous_category_id = $transaction->finance_category_id === null ? null : (int) $transaction->finance_category_id;
			$previous_incoming = $transaction->incoming === null ? null : (int) $transaction->incoming;
			$previous_rule_id = $transaction->finance_rule_id === null ? null : (int) $transaction->finance_rule_id;

			[$category, $rule] = $categorizer->categorize_with_rule($transaction);
			if ($category !== null) {
				$new_category_id = (int) $category->id;
				$new_rule_id = (int) $rule->id;
				$new_incoming = null;
			} else {
				$new_category_id = null;
				$new_rule_id = null;
				$new_incoming = $transaction->is_incoming() === true ? 1 : 0;
			}

			if ($new_category_id === $previous_category_id && $new_incoming === $previous_incoming && $new_rule_id === $previous_rule_id) {
				continue;
			}

			$transaction->finance_category_id = $new_category_id;
			$transaction->finance_rule_id = $new_rule_id;
			$transaction->incoming = $new_incoming;
			$transaction->save();
			$reindexed++;
		}

		$uncategorized = (int) $database->get_one(
			'SELECT COUNT(*) FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `finance_category_id` IS NULL
			AND `internal` = 0',
			[]
		);

		return [
			self::RESULT_REINDEXED => $reindexed,
			self::RESULT_TRANSFERS => $transfers,
			self::RESULT_UNCATEGORIZED => $uncategorized,
		];
	}
}
