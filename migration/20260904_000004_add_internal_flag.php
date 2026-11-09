<?php
/**
 * Add internal transfer flag to bank_transaction
 *
 * Adds a boolean `internal` column that marks transactions moving money
 * between two of the user's own bank accounts. Flagged transactions are
 * excluded from the statistics (a transfer is neither income nor expense
 * and would be double counted) and from the uncategorized triage page.
 *
 * Existing transactions are backfilled by extracting the Belgian IBAN from
 * the counterparty account, description and communication fields and
 * matching it against the IBANs of the configured bank accounts. The
 * extraction is deliberately self-contained (it does not use the models)
 * so this migration keeps working even when the application code changes.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Migration_20260904_000004_Add_Internal_Flag extends \Skeleton\Database\Migration {

	/**
	 * Up
	 *
	 * @access public
	 */
	public function up(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_transaction`
			ADD COLUMN `internal` TINYINT(1) DEFAULT NULL AFTER `incoming`
		');

		// Normalized IBAN of every configured account, keyed by id so a
		// transaction's own account can be excluded from the match
		$own_ibans = [];
		$accounts = $database->get_all('
			SELECT `id`, `iban` FROM `bank_account`
			WHERE `archived` IS NULL
			AND `iban` IS NOT NULL
		');
		foreach ($accounts as $account) {
			$own_ibans[(int) $account['id']] = self::normalize_iban($account['iban']);
		}

		$transactions = $database->get_all('
			SELECT `id`, `bank_account_id`, `counterparty_account`, `description`, `communication`
			FROM `bank_transaction`
		');

		foreach ($transactions as $transaction) {
			if (self::matches_own_account($transaction, $own_ibans) === false) {
				continue;
			}

			$database->query('
				UPDATE `bank_transaction`
				SET `internal` = 1
				WHERE `id` = ?
			', [ $transaction['id'] ]);
		}
	}

	/**
	 * Down
	 *
	 * @access public
	 */
	public function down(): void {
		$database = Database::get();
		$database->query('
			ALTER TABLE `bank_transaction`
			DROP COLUMN `internal`
		');
	}

	/**
	 * Check whether a transaction row points at another configured account
	 *
	 * @access private
	 * @param array $transaction
	 * @param array $own_ibans Normalized IBANs keyed by bank_account id
	 * @return bool $matches
	 */
	private static function matches_own_account(array $transaction, array $own_ibans): bool {
		$fields = [ $transaction['counterparty_account'], $transaction['description'], $transaction['communication'] ];

		foreach ($fields as $field) {
			$iban = self::extract_belgian_iban($field);
			if ($iban === null) {
				continue;
			}

			foreach ($own_ibans as $bank_account_id => $own_iban) {
				if ((int) $bank_account_id === (int) $transaction['bank_account_id']) {
					continue;
				}

				if ($own_iban === $iban) {
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
	 * @access private
	 * @param ?string $value
	 * @return ?string $iban
	 */
	private static function extract_belgian_iban(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		if (preg_match('/\b(BE\d{2}(?:[\s-]?[A-Z0-9]{4}){3})\b/i', $value, $matches) !== 1) {
			return null;
		}

		return self::normalize_iban($matches[1]);
	}

	/**
	 * Normalize an IBAN for comparison
	 *
	 * @access private
	 * @param ?string $iban
	 * @return string $normalized
	 */
	private static function normalize_iban(?string $iban): string {
		return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $iban) ?? '');
	}
}
