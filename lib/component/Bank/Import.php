<?php
/**
 * Bank_Import
 *
 * Parses a bank account export (CSV) into transactions and stores them.
 * Supports two export formats, detected automatically:
 *
 * - KBC: semicolon-delimited with 18+ columns and no header row
 * - Keytrade: semicolon-delimited with a header row of 7 columns
 *
 * Every transaction is deduplicated against the account's existing
 * transactions (re-importing an export never creates duplicates) and gets a
 * budget category assigned by the rule-based categorizer. Transactions that
 * move money to another configured bank account (an internal transfer) are
 * flagged instead of categorized, so they never inflate the statistics.
 * The result reports how many transactions were imported, skipped and
 * categorized, and how many internal transfers were detected.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

class Bank_Import {

	/**
	 * Result key: transactions stored
	 *
	 * @var string $result_imported
	 */
	const RESULT_IMPORTED = 'imported';

	/**
	 * Result key: duplicate transactions skipped
	 *
	 * @var string $result_skipped
	 */
	const RESULT_SKIPPED = 'skipped';

	/**
	 * Result key: transactions left without a category
	 *
	 * @var string $result_uncategorized
	 */
	const RESULT_UNCATEGORIZED = 'uncategorized';

	/**
	 * Result key: transactions detected as internal transfers
	 *
	 * @var string $result_internal
	 */
	const RESULT_INTERNAL = 'transfers';

	/**
	 * Import the transactions of a bank export file into an account
	 *
	 * @access public
	 * @param string $csv_path
	 * @param \Bank_Account $bank_account
	 * @return array $result
	 */
	public static function import(string $csv_path, \Bank_Account $bank_account): array {
		$content = self::read_normalized_csv($csv_path);
		$rows = self::parse_rows($content);

		return self::import_rows($rows, $bank_account);
	}

	/**
	 * Import a list of parsed rows into an account
	 *
	 * Deduplicates, categorizes and stores every row. Rows whose
	 * counterparty is another configured account are flagged as internal
	 * transfers and left uncategorized. Shared by the direct import and the
	 * two-step preview flow (which parses once and stores the rows between
	 * the preview and the confirmed import).
	 *
	 * @access public
	 * @param array $rows
	 * @param \Bank_Account $bank_account
	 * @return array $result
	 */
	public static function import_rows(array $rows, \Bank_Account $bank_account): array {
		if (count($rows) === 0) {
			return [
				self::RESULT_IMPORTED => 0,
				self::RESULT_SKIPPED => 0,
				self::RESULT_UNCATEGORIZED => 0,
				self::RESULT_INTERNAL => 0,
			];
		}

		$categorizer = new \Bank_Categorizer();
		$bank_accounts = \Bank_Account::get_all_active();
		$result = [
			self::RESULT_IMPORTED => 0,
			self::RESULT_SKIPPED => 0,
			self::RESULT_UNCATEGORIZED => 0,
			self::RESULT_INTERNAL => 0,
		];

		foreach ($rows as $row) {
			$transaction = self::build_transaction($row, $bank_account);
			if ($transaction === null) {
				continue;
			}

			if (\Bank_Transaction::get_by_import_key(
				$bank_account->id,
				$transaction->transaction_number,
				$transaction->value_date,
				(float) $transaction->amount,
				$transaction->communication
			) !== null) {
				$result[self::RESULT_SKIPPED]++;
				continue;
			}

			if ($transaction->is_internal_transfer($bank_accounts) === true) {
				$transaction->internal = 1;
				$transaction->incoming = (float) $transaction->amount > 0.0;
				$transaction->save();
				$result[self::RESULT_IMPORTED]++;
				$result[self::RESULT_INTERNAL]++;
				continue;
			}

			$category = $categorizer->categorize_with_rule($transaction);
			if ($category[0] !== null) {
				$transaction->finance_category_id = $category[0]->id;
				$transaction->finance_rule_id = $category[1]->id;
			} else {
				$transaction->incoming = (float) $transaction->amount > 0.0;
				$result[self::RESULT_UNCATEGORIZED]++;
			}

			$transaction->save();
			$result[self::RESULT_IMPORTED]++;
		}

		return $result;
	}

	/**
	 * Detect which configured account a parsed export belongs to
	 *
	 * Every parsed row carries the account identifier of the exporting bank
	 * account in its `account` field (the rekeningnummer for KBC). The
	 * first non-empty one is matched against the configured accounts by
	 * IBAN. Keytrade exports carry no own-account column and parse with
	 * `account = null`, so they can never be detected this way and fall
	 * back to the account chosen at the preview.
	 *
	 * @access public
	 * @param array $rows
	 * @return ?Bank_Account $bank_account
	 */
	public static function detect_account(array $rows): ?\Bank_Account {
		foreach ($rows as $row) {
			if (empty($row['account']) === true) {
				continue;
			}

			$bank_account = \Bank_Account::get_by_iban($row['account']);
			if ($bank_account !== null) {
				return $bank_account;
			}
		}

		return null;
	}

	/**
	 * Read a CSV file and return its normalized, line-split content
	 *
	 * Removes a UTF-8 BOM, converts ISO-8859-1 to UTF-8 when needed and
	 * normalizes CR/CRLF line endings to LF.
	 *
	 * @access public
	 * @param string $csv_path
	 * @return array $lines
	 */
	public static function read_normalized_csv(string $csv_path): array {
		$content = file_get_contents($csv_path);
		if ($content === false) {
			throw new \Exception('Could not read bank export: ' . $csv_path);
		}

		$bom = "\xEF\xBB\xBF";
		if (strncmp($content, $bom, 3) === 0) {
			$content = substr($content, 3);
		}

		if (mb_check_encoding($content, 'UTF-8') === false) {
			$content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
		}

		$content = str_replace("\r\n", "\n", $content);
		$content = str_replace("\r", "\n", $content);

		return explode("\n", $content);
	}

	/**
	 * Read a CSV file and return a fully escaped-aware row matrix
	 *
	 * Unlike read_normalized_csv() this understands quoted fields that
	 * span multiple lines (bank communication texts often contain
	 * carriage returns): the normalized content is streamed through
	 * fgetcsv, which respects the CSV quoting. Encoding handling (BOM,
	 * ISO-8859-1) is shared with read_normalized_csv.
	 *
	 * @access public
	 * @param string $csv_path
	 * @return array $rows
	 */
	public static function read_escaped_csv(string $csv_path): array {
		$content = file_get_contents($csv_path);
		if ($content === false) {
			throw new \Exception('Could not read csv: ' . $csv_path);
		}

		$bom = "\xEF\xBB\xBF";
		if (strncmp($content, $bom, 3) === 0) {
			$content = substr($content, 3);
		}

		if (mb_check_encoding($content, 'UTF-8') === false) {
			$content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
		}

		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			throw new \Exception('Could not open temp stream');
		}

		fwrite($stream, $content);
		rewind($stream);

		$rows = [];
		while (($fields = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
			if (count($fields) === 1 && $fields[0] === null) {
				continue;
			}

			$rows[] = $fields;
		}
		fclose($stream);

		return $rows;
	}

	/**
	 * Parse the normalized lines into uniform transaction rows
	 *
	 * The export format is detected from the first non-empty line, then
	 * every row is converted into the shared shape:
	 * account, transaction_number, value_date, transaction_date, description,
	 * counterparty_name, counterparty_account, amount, currency, balance,
	 * communication.
	 *
	 * @access public
	 * @param array $lines
	 * @return array $rows
	 */
	public static function parse_rows(array $lines): array {
		$header = self::find_header($lines);

		if ($header !== null && count($header) >= 7 && strcasecmp($header[0], 'Uittreksel') === 0) {
			return self::parse_keytrade($lines, $header);
		}

		return self::parse_kbc($lines);
	}

	/**
	 * Parse the KBC export format
	 *
	 * Semicolon-delimited, no header, at least 18 columns per row.
	 *
	 * @access public
	 * @param array $lines
	 * @return array $rows
	 */
	public static function parse_kbc(array $lines): array {
		$rows = [];
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$fields = str_getcsv($line, ';', '"', '\\');
			if (count($fields) < 18) {
				continue;
			}

			if (strcasecmp($fields[0], 'Rekeningnummer') === 0) {
				continue;
			}

			$row = [
				'account' => trim($fields[0]),
				'transaction_number' => trim($fields[4]),
				'value_date' => self::parse_date($fields[5]),
				'description' => self::empty_to_null(trim($fields[6])),
				'transaction_date' => self::parse_date($fields[7]),
				'amount' => self::parse_amount($fields[8]),
				'currency' => trim($fields[3]),
				'balance' => self::parse_amount($fields[9]),
				'counterparty_account' => self::empty_to_null(trim($fields[12])),
				'counterparty_bic' => self::empty_to_null(trim($fields[13])),
				'counterparty_name' => self::empty_to_null(trim($fields[14])),
				'communication' => self::empty_to_null(trim($fields[17])),
				'counterparty_iban' => null,
			];

			if ($row['account'] === '' || $row['transaction_number'] === '' || $row['value_date'] === null || $row['amount'] === 0.0) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Parse the Keytrade export format
	 *
	 * Semicolon-delimited with a header row:
	 * Uittreksel;Datum;Valutadatum;IBAN;Omschrijving;Bedrag;Valuta
	 *
	 * The IBAN column is NOT the exporting account: it holds the
	 * counterparty's IBAN for transfers or a dash for card payments (the
	 * export never names its own account). `account` is therefore null —
	 * detect_account() must not match counterparty IBANs against the
	 * configured accounts, or a Keytrade file would "belong" to whatever
	 * account its first transfer points at.
	 *
	 * @access public
	 * @param array $lines
	 * @param array $header
	 * @return array $rows
	 */
	public static function parse_keytrade(array $lines, array $header): array {
		$rows = [];
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$fields = str_getcsv($line, ';', '"', '\\');
			if (count($fields) < 7 || strcasecmp($fields[0], 'Uittreksel') === 0) {
				continue;
			}

			$row = [
				'account' => null,
				'transaction_number' => trim($fields[0]),
				'transaction_date' => self::parse_date($fields[1]),
				'value_date' => self::parse_date($fields[2]),
				'description' => self::empty_to_null(trim($fields[4])),
				'amount' => self::parse_amount($fields[5]),
				'currency' => trim($fields[6]),
				'balance' => null,
				'counterparty_account' => null,
				'counterparty_bic' => null,
				'counterparty_name' => null,
				'communication' => self::empty_to_null(trim($fields[4])),
				'counterparty_iban' => null,
			];

			if ($row['account'] === '' || $row['transaction_number'] === '' || $row['value_date'] === null || $row['transaction_date'] === null || $row['amount'] === 0.0) {
				continue;
			}

			$names = self::extract_keytrade_counterparty($fields[4]);
			$row['counterparty_name'] = $names['name'];
			$row['counterparty_account'] = $names['account'];
			$row['counterparty_iban'] = $names['account'];

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Find the first non-empty line and parse it as the header candidate
	 *
	 * @access public
	 * @param array $lines
	 * @return ?array $header
	 */
	public static function find_header(array $lines): ?array {
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$fields = str_getcsv($line, ';', '"', '\\');

			return $fields;
		}

		return null;
	}

	/**
	 * Build a transaction object from a parsed row
	 *
	 * @access private
	 * @param array $row
	 * @param \Bank_Account $bank_account
	 * @return ?Bank_Transaction $transaction
	 */
	private static function build_transaction(array $row, \Bank_Account $bank_account): ?\Bank_Transaction {
		$transaction = new \Bank_Transaction();
		$transaction->bank_account_id = $bank_account->id;
		$transaction->transaction_number = $row['transaction_number'];
		$transaction->value_date = $row['value_date'];
		$transaction->transaction_date = $row['transaction_date'];
		$transaction->description = $row['description'];
		$transaction->counterparty_name = $row['counterparty_name'];
		$transaction->counterparty_account = $row['counterparty_account'];
		$transaction->amount = $row['amount'];
		$transaction->currency = $row['currency'];
		$transaction->balance = $row['balance'];
		$transaction->communication = $row['communication'];
		$transaction->internal = 0;

		return $transaction;
	}

	/**
	 * Extract the counterparty name and IBAN from a Keytrade description
	 *
	 * Keytrade packs the counterparty into the free description in the form
	 * "NAME IBAN ...". The IBAN is the token that starts with BE followed by
	 * digits; the remainder before it is the counterparty name.
	 *
	 * @access private
	 * @param string $description
	 * @return array $extracted
	 */
	private static function extract_keytrade_counterparty(string $description): array {
		if (preg_match('/\b(BE\d{2}[A-Z0-9]{11,})\b/', $description, $matches) === 1) {
			$account = $matches[1];
			$name = trim(substr($description, 0, strpos($description, $account)));

			return [ 'name' => $name === '' ? null : $name, 'account' => $account ];
		}

		return [ 'name' => null, 'account' => null ];
	}

	/**
	 * Parse a decimal amount
	 *
	 * Converts the Belgian decimal separator (comma) to a dot.
	 *
	 * @access private
	 * @param string $value
	 * @return float $amount
	 */
	private static function parse_amount(string $value): float {
		return (float) str_replace(',', '.', trim($value));
	}

	/**
	 * Parse a d/m/Y date to a MySQL date
	 *
	 * @access private
	 * @param string $value
	 * @return ?string $date
	 */
	private static function parse_date(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$date_time = \DateTime::createFromFormat('d/m/Y', $value);
		if ($date_time === false) {
			return null;
		}

		return $date_time->format('Y-m-d');
	}

	/**
	 * Convert an empty string to null
	 *
	 * @access private
	 * @param string $value
	 * @return ?string $value
	 */
	private static function empty_to_null(string $value): ?string {
		return $value === '' ? null : $value;
	}
}
