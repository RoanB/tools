<?php
/**
 * Bank_Transaction
 *
 * A single bank transaction imported from a bank account export. Every
 * transaction belongs to a bank account and may be assigned a budget
 * category, either automatically through finance rules or manually.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Bank_Transaction {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Get the bank account this transaction belongs to
	 *
	 * @access public
	 * @return Bank_Account $bank_account
	 */
	public function get_bank_account(): Bank_Account {
		return \Bank_Account::get_by_id($this->bank_account_id);
	}

	/**
	 * Get the category assigned to this transaction
	 *
	 * @access public
	 * @return ?Finance_Category $category
	 */
	public function get_category(): ?Finance_Category {
		if (empty($this->finance_category_id) === true) {
			return null;
		}

		return \Finance_Category::get_by_id($this->finance_category_id);
	}

	/**
	 * Get the rule that assigned this transaction's category
	 *
	 * Null for manual assignments and for rows imported before the
	 * audit column existed. The linked rule may be archived since: the
	 * link records what happened, not what is currently configured.
	 *
	 * @access public
	 * @return ?Finance_Rule $rule
	 */
	public function get_rule(): ?Finance_Rule {
		if (empty($this->finance_rule_id) === true) {
			return null;
		}

		try {
			return \Finance_Rule::get_by_id($this->finance_rule_id);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Check whether the transaction has a category assigned
	 *
	 * @access public
	 * @return bool $categorized
	 */
	public function is_categorized(): bool {
		return $this->finance_category_id !== null;
	}

	/**
	 * True for incoming transactions (positive amount)
	 *
	 * @access public
	 * @return bool $incoming
	 */
	public function is_incoming(): bool {
		return (float) $this->amount > 0.0;
	}

	/**
	 * Check whether this transaction moves money to another own account
	 *
	 * A transaction counts as an internal transfer when one of its text
	 * fields holds the IBAN of another configured bank account. The
	 * accounts are passed in by the caller so import loops only fetch
	 * them once.
	 *
	 * @access public
	 * @param array $bank_accounts
	 * @return bool $internal
	 */
	public function is_internal_transfer(array $bank_accounts): bool {
		$fields = [ $this->counterparty_account, $this->description, $this->communication ];

		foreach ($fields as $field) {
			$iban = self::extract_belgian_iban($field);
			if ($iban === null) {
				continue;
			}

			foreach ($bank_accounts as $bank_account) {
				if ((int) $bank_account->id === (int) $this->bank_account_id) {
					continue;
				}

				if (\Bank_Account::normalize_iban((string) $bank_account->iban) === $iban) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract a Belgian IBAN from a free-text field
	 *
	 * Matches the 16-character Belgian format with or without separators
	 * (BE68539007547034, BE68 5390 0754 7034, BE68-5390-0754-7034) and
	 * returns it normalized, or null when the text holds none.
	 *
	 * @access public
	 * @param ?string $value
	 * @return ?string $iban
	 */
	public static function extract_belgian_iban(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		if (preg_match('/\b(BE\d{2}(?:[\s-]?[A-Z0-9]{4}){3})\b/i', $value, $matches) !== 1) {
			return null;
		}

		return \Bank_Account::normalize_iban($matches[1]);
	}

	/**
	 * Find a transaction by its bank import key
	 *
	 * The statement number in a bank export is only unique per account, so
	 * the combination of account, value date and amount identifies each
	 * transaction once. Re-importing an export therefore does not create
	 * duplicates.
	 *
	 * The transaction number is compared without leading zeros: KBC
	 * started zero-padding its extract numbers (2026021 -> 02026021),
	 * which would otherwise defeat the dedup and store a re-imported
	 * statement twice. The number is stored as the bank delivers it; only
	 * the comparison is normalized.
	 *
	 * @access public
	 * @param int $bank_account_id
	 * @param ?string $transaction_number
	 * @param ?string $value_date
	 * @param float $amount
	 * @param ?string $communication
	 * @return ?Bank_Transaction $transaction
	 */
	public static function get_by_import_key(int $bank_account_id, ?string $transaction_number, ?string $value_date, float $amount, ?string $communication): ?self {
		$database = Database::get();
		$id = $database->get_one(
			'SELECT `id` FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `bank_account_id` = ?
			AND (TRIM(LEADING \'0\' FROM `transaction_number`) <=> TRIM(LEADING \'0\' FROM ?))
			AND (`value_date` <=> ?)
			AND `amount` = ?
			AND (`communication` <=> ?)',
			[ $bank_account_id, $transaction_number, $value_date, $amount, $communication ]
		);

		if ($id === null) {
			return null;
		}

		return self::get_by_id($id);
	}

	/**
	 * Archive the transaction
	 *
	 * Archived transactions are hidden from every list and filter but stay
	 * in the database, so a wrong import can be undone without losing the
	 * data.
	 *
	 * @access public
	 */
	public function archive(): void {
		$this->archived = date('Y-m-d H:i:s');
		$this->save();
	}

	/**
	 * Restore an archived transaction
	 *
	 * @access public
	 */
	public function restore(): void {
		$this->archived = null;
		$this->save();
	}

	/**
	 * Get the overview statistics for one account
	 *
	 * Incoming, outgoing and uncategorized amounts are summed separately so
	 * outgoings never cancel out the incoming column. Internal transfers
	 * between own accounts are excluded: they are neither income nor
	 * expense. When a year is given, only transactions whose value date
	 * falls in that year are counted.
	 *
	 * @access public
	 * @param int $bank_account_id
	 * @param ?int $year
	 * @return array $stats
	 */
	public static function get_stats(int $bank_account_id, ?int $year = null): array {
		$database = Database::get();
		$where = 'WHERE `archived` IS NULL
			AND `internal` = 0
			AND `bank_account_id` = ?';
		$params = [ $bank_account_id ];

		if ($year !== null) {
			$where .= ' AND YEAR(`value_date`) = ?';
			$params[] = $year;
		}

		$row = $database->get_row(
			'SELECT
				COUNT(*) AS `count`,
				SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END) AS `amount_in`,
				SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END) AS `amount_out`,
				SUM(CASE WHEN `finance_category_id` IS NULL THEN 1 ELSE 0 END) AS `uncategorized`
			FROM `bank_transaction`
			' . $where,
			$params
		);

		return [
			'count' => (int) $row['count'],
			'amount_in' => (float) $row['amount_in'],
			'amount_out' => (float) $row['amount_out'],
			'uncategorized' => (int) $row['uncategorized'],
		];
	}

	/**
	 * Get the overview statistics across all accounts
	 *
	 * Internal transfers between own accounts are excluded: they are
	 * neither income nor expense. When a year is given, only transactions
	 * whose value date falls in that year are counted.
	 *
	 * @access public
	 * @param ?int $year
	 * @return array $stats
	 */
	public static function get_all_stats(?int $year = null): array {
		$database = Database::get();
		$where = 'WHERE `archived` IS NULL
			AND `internal` = 0';
		$params = [];

		if ($year !== null) {
			$where .= ' AND YEAR(`value_date`) = ?';
			$params[] = $year;
		}

		$row = $database->get_row(
			'SELECT
				COUNT(*) AS `count`,
				SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END) AS `amount_in`,
				SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END) AS `amount_out`,
				SUM(CASE WHEN `finance_category_id` IS NULL THEN 1 ELSE 0 END) AS `uncategorized`
			FROM `bank_transaction`
			' . $where,
			$params
		);

		return [
			'count' => (int) $row['count'],
			'amount_in' => (float) $row['amount_in'],
			'amount_out' => (float) $row['amount_out'],
			'uncategorized' => (int) $row['uncategorized'],
		];
	}

	/**
	 * Assign a category to the transaction and save it
	 *
	 * @access public
	 * @param Finance_Category $category
	 */
	public function assign_category(Finance_Category $category): void {
		$this->finance_category_id = $category->id;
		$this->save();
	}

	/**
	 * Remove the assigned category from the transaction and save it
	 *
	 * @access public
	 */
	public function remove_category(): void {
		$this->finance_category_id = null;
		$this->save();
	}
}
