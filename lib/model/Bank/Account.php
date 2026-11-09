<?php
/**
 * Bank_Account
 *
 * A bank account (for example a KBC or Keytrade account) whose CSV exports
 * are imported into the finances area. Imported transactions are grouped per
 * account so the same statement number never collides between accounts.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Bank_Account {
	use \Skeleton\Object\Model;
	use \Skeleton\Object\Uuid;
	use \Skeleton\Object\Get;
	use \Skeleton\Object\Save;
	use \Skeleton\Object\Delete;
	use \Skeleton\Pager\Page;

	/**
	 * Short labels for the known banks, keyed by first word of the name
	 *
	 * @var array $short_names
	 */
	protected static $short_names = [
		'kbc' => 'KBC',
		'keytrade' => 'KEYT',
	];

	/**
	 * Get a short label of the account for compact lists
	 *
	 * Accounts whose name starts with a known bank (KBC, Keytrade) get
	 * their fixed label; other accounts keep their full name so the
	 * column never shows a meaningless abbreviation.
	 *
	 * @access public
	 * @return string $short_name
	 */
	public function get_short_name(): string {
		$name = trim((string) $this->name);

		if ($name === '') {
			return '';
		}

		$first_word = strtolower(explode(' ', $name)[0]);

		if (isset(self::$short_names[$first_word]) === true) {
			return self::$short_names[$first_word];
		}

		return $name;
	}

	/**
	 * Get all active bank accounts ordered by name
	 *
	 * Includes savings accounts: their IBANs must participate in the
	 * internal-transfer check and export detection.
	 *
	 * @access public
	 * @return array $bank_accounts
	 */
	public static function get_all_active(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `bank_account`
			WHERE `archived` IS NULL
			ORDER BY `name`',
			[]
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Get the active bank accounts that can receive an import
	 *
	 * Savings accounts hold no imported statements, so they are excluded
	 * from the account switcher, the import target and the list filters.
	 * They still exist for the internal-transfer check: transaction
	 * fields holding the IBAN of a savings account are internal.
	 *
	 * @access public
	 * @return array $bank_accounts
	 */
	public static function get_all_uploadable(): array {
		$database = Database::get();
		$ids = $database->get_column(
			'SELECT `id` FROM `bank_account`
			WHERE `archived` IS NULL
			AND `savings` = 0
			ORDER BY `name`',
			[]
		);

		return self::get_by_ids($ids);
	}

	/**
	 * Whether this account is a savings account
	 *
	 * @access public
	 * @return bool $savings
	 */
	public function is_savings(): bool {
		return (int) $this->savings === 1;
	}

	/**
	 * Find a bank account by its IBAN
	 *
	 * Both the stored IBAN and the looked-up IBAN are normalized (spaces and
	 * dashes removed, uppercased) before comparison.
	 *
	 * @access public
	 * @param string $iban
	 * @return ?Bank_Account $bank_account
	 */
	public static function get_by_iban(string $iban): ?self {
		$database = Database::get();
		$normalized = self::normalize_iban($iban);

		$ids = $database->get_column(
			'SELECT `id` FROM `bank_account`
			WHERE `archived` IS NULL
			AND `iban` IS NOT NULL',
			[]
		);

		foreach (self::get_by_ids($ids) as $bank_account) {
			if (self::normalize_iban($bank_account->iban) === $normalized) {
				return $bank_account;
			}
		}

		return null;
	}

	/**
	 * Normalize an IBAN for comparison
	 *
	 * @access public
	 * @param string $iban
	 * @return string $normalized
	 */
	public static function normalize_iban(string $iban): string {
		return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $iban) ?? '');
	}
}
