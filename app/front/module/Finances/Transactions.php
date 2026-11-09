<?php
/**
 * Module Finances Transactions
 *
 * Archive of every imported bank transaction across all accounts, with an
 * extensive filter (account, year, date range, category, direction, amount
 * range and free text search) and a paged, sortable table.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module\Finances;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;
use Skeleton\Core\Http\Session;

class Transactions extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'finances/transactions.twig';

	/**
	 * Secure access
	 *
	 * @access public
	 * @return bool $allowed
	 */
	public function secure(): bool {
		return isset($_SESSION['user_id']);
	}

	/**
	 * Display the transaction archive
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();
		$template->assign('bank_accounts', \Bank_Account::get_all_uploadable());

		$template->assign('years', $this->get_all_years());
		$template->assign('categories', \Finance_Category::get_all_active());
		$template->assign('current_uri', $_SERVER['REQUEST_URI'] ?? '/finances/transactions');

		$pager = $this->get_transaction_pager();
		$template->assign('pager', $pager);
		$template->assign('header_value_date', $pager->create_header('Date', 'value_date'));
		$template->assign('header_account', $pager->create_header('Account', 'bank_account.name'));
		$template->assign('header_counterparty_name', $pager->create_header('Counterparty', 'counterparty_name'));
		$template->assign('header_description', $pager->create_header('Description', 'description'));
		$template->assign('header_category', $pager->create_header('Category', 'finance_category.name'));
		$template->assign('header_amount', $pager->create_header('Amount', 'amount'));
		$template->assign('filter_totals', $this->get_filter_totals($pager));

		// The export reuses the current query string so the downloaded CSV
		// covers exactly the filter state the list shows
		$current_request = $_SERVER['REQUEST_URI'] ?? '/finances/transactions';
		$query = (string) (parse_url($current_request, PHP_URL_QUERY) ?? '');
		$template->assign('export_url', '/finances/transactions?action=export' . ($query === '' ? '' : '&' . $query));

		$filter_account = $this->get_account_filter();
		$filter_year = $this->get_year_filter();
		$filter_category = $this->get_category_filter();
		$filter_direction = $this->get_direction_filter();
		$filter_internal = $this->get_internal_filter();
		$filter_date_from = $this->get_date_filter('date_from');
		$filter_date_to = $this->get_date_filter('date_to');
		$filter_amount_min = $this->get_amount_filter('amount_min');
		$filter_amount_max = $this->get_amount_filter('amount_max');
		$filter_status = $this->get_status_filter();

		$template->assign('filter_account', $filter_account);
		$template->assign('filter_year', $filter_year);
		$template->assign('filter_category', $filter_category);
		$template->assign('filter_direction', $filter_direction);
		$template->assign('filter_internal', $filter_internal);
		$template->assign('filter_date_from', $filter_date_from);
		$template->assign('filter_date_to', $filter_date_to);
		$template->assign('filter_amount_min', $filter_amount_min);
		$template->assign('filter_amount_max', $filter_amount_max);
		$template->assign('filter_status', $filter_status);
		$template->assign('filter_search', $pager->get_search());

		// The rarely used filters sit in a collapsed panel; it opens
		// automatically when one of them is active so an incoming link like
		// ?internal=internal still shows its filter in use
		$advanced_count = 0;
		foreach ([ $filter_year, $filter_date_from, $filter_date_to, $filter_amount_min, $filter_amount_max, $filter_internal ] as $advanced_value) {
			if ($advanced_value !== '') {
				$advanced_count++;
			}
		}

		$template->assign('filter_advanced_count', $advanced_count);
	}

	/**
	 * Export the filtered transactions as CSV
	 *
	 * Streams the transactions of the current filter state (the same
	 * conditions the list shows) as a download: id, date, counterparty,
	 * description, communication, amount, account and category. The
	 * category column can be edited offline and the file re-uploaded via
	 * the category import — nothing leaves the server except this
	 * download itself.
	 *
	 * @access public
	 */
	public function display_export(): void {
		$pager = $this->get_transaction_pager();
		$conditions = $pager->get_conditions();

		$category_names = [];
		foreach (\Finance_Category::get_all_active() as $category) {
			$category_names[(int) $category->id] = $category->name;
		}

		$file = fopen('php://temp', 'w+');
		fputcsv($file, [ 'id', 'value_date', 'counterparty_name', 'description', 'communication', 'amount', 'account', 'category' ], ',', '"', '\\');

		$page = 1;
		do {
			$transactions = \Bank_Transaction::get_paged('value_date', 'desc', $page, $conditions, true);
			foreach ($transactions as $transaction) {
				$category = $transaction->finance_category_id === null ? '' : ($category_names[(int) $transaction->finance_category_id] ?? '');

				fputcsv($file, [
					$transaction->id,
					$transaction->value_date,
					self::csv_inline((string) $transaction->counterparty_name),
					self::csv_inline((string) $transaction->description),
					self::csv_inline((string) $transaction->communication),
					$transaction->amount,
					$transaction->get_bank_account()->name,
					$category,
				], ',', '"', '\\');
			}
			$page++;
		} while (count($transactions) === 1000);

		rewind($file);
		$content = stream_get_contents($file);
		fclose($file);

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');
		header('Content-Length: ' . strlen($content));
		echo $content;
		exit;
	}

	/**
	 * Flatten a text field to a single CSV line
	 *
	 * Bank texts carry carriage returns and newlines inside the
	 * communication; they would become row breaks in spreadsheet tools
	 * that edit the file line-based. Collapsing them to spaces keeps the
	 * review sheet one-row-per-transaction.
	 *
	 * @access private
	 * @param string $value
	 * @return string $value
	 */
	private static function csv_inline(string $value): string {
		return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
	}

	/**
	 * Show the category import form and preview
	 *
	 * The uploaded CSV (an export of this page, possibly edited offline)
	 * is parsed into a diff: transaction id, current category and the
	 * target category read from the category column by exact name. The
	 * diff is held in the session until the user confirms; nothing is
	 * applied yet.
	 *
	 * @access public
	 */
	public function display_import_categories(): void {
		$this->template = 'finances/import_categories.twig';
		$template = Template::get();
		$template->assign('categories', \Finance_Category::get_all_active());

		if (isset($_POST['import_preview']) === false) {
			return;
		}

		if (isset($_FILES['file']) === false || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			Session::set_sticky('finance_transactions_result', [ 'error' => 'upload_failed' ]);
			Session::redirect('/finances/transactions?action=import_categories');
		}

		try {
			$rows = \Bank_Import::read_escaped_csv($_FILES['file']['tmp_name']);
		} catch (\Exception $e) {
			error_log('Category import read failed: ' . $e->getMessage());
			Session::set_sticky('finance_transactions_result', [ 'error' => 'import_failed' ]);
			Session::redirect('/finances/transactions?action=import_categories');
		}

		$category_ids = [];
		$category_names = [];
		foreach (\Finance_Category::get_all_active() as $category) {
			$category_ids[$category->name] = (int) $category->id;
			$category_names[(int) $category->id] = $category->name;
		}

		$diff = $this->parse_category_csv($rows, $category_ids, $category_names);

		if (count($diff['changes']) === 0) {
			Session::set_sticky('finance_transactions_result', [ 'error' => 'no_changes' ]);
			Session::redirect('/finances/transactions?action=import_categories');
		}

		$_SESSION['finance_category_import'] = $diff;

		$template->assign('diff', $diff);
	}

	/**
	 * Apply the previewed category import exactly once
	 *
	 * Consumes the session diff and applies every pending change:
	 * category by id, the audit link cleared (manual assignment) and the
	 * incoming flag cleared. Rows whose category did not actually change
	 * are not saved.
	 *
	 * @access public
	 */
	public function display_import_categories_confirm(): void {
		$diff = $_SESSION['finance_category_import'] ?? null;
		if (is_array($diff) === false || isset($diff['changes']) === false) {
			Session::set_sticky('finance_transactions_result', [ 'error' => 'expired' ]);
			Session::redirect('/finances/transactions?action=import_categories');
		}

		$applied = 0;
		foreach ($diff['changes'] as $transaction_id => $change) {
			$transaction = \Bank_Transaction::get_by_id((int) $transaction_id);
			if ($transaction->id === null) {
				continue;
			}

			$transaction->finance_category_id = (int) $change['to_id'];
			$transaction->incoming = null;
			$transaction->finance_rule_id = null;
			$transaction->save();
			$applied++;
		}

		unset($_SESSION['finance_category_import']);

		Session::set_sticky('finance_transactions_result', [
			'imported' => $applied,
			'skipped_ids' => (int) ($diff['skipped_ids'] ?? 0),
			'skipped_names' => count($diff['skipped_names'] ?? []),
		]);

		Session::redirect('/finances/transactions');
	}

	/**
	 * Parse an uploaded category CSV into a change diff
	 *
	 * Reads id and category columns; unknown ids (and archived rows) and
	 * unresolvable category names are skipped and counted. Empty
	 * category cells mean "no change". The diff holds only rows whose
	 * category would actually change.
	 *
	 * @access private
	 * @param array $rows
	 * @param array $category_ids
	 * @param array $category_names
	 * @return array $diff
	 */
	private function parse_category_csv(array $rows, array $category_ids, array $category_names): array {
		$diff = [
			'changes' => [],
			'skipped_ids' => 0,
			'skipped_names' => [],
		];

		foreach ($rows as $fields) {
			$id = (int) trim((string) ($fields[0] ?? ''));
			if ($id < 1) {
				continue;
			}

			$category_name = trim((string) ($fields[7] ?? ''));
			if ($category_name === '') {
				continue;
			}

			if (isset($category_ids[$category_name]) === false) {
				$diff['skipped_names'][] = $category_name;
				continue;
			}

			try {
				$transaction = \Bank_Transaction::get_by_id($id);
			} catch (\Exception $e) {
				$transaction = null;
			}

			if ($transaction === null || $transaction->id === null || $transaction->archived !== null) {
				$diff['skipped_ids']++;
				continue;
			}

			$from_id = $transaction->finance_category_id === null ? null : (int) $transaction->finance_category_id;
			$to_id = $category_ids[$category_name];
			if ($from_id === $to_id) {
				continue;
			}

			$diff['changes'][$id] = [
				'from' => $from_id === null ? '' : ($category_names[$from_id] ?? ''),
				'to' => $category_name,
				'to_id' => $to_id,
			];
		}

		return $diff;
	}

	/**
	 * Archive a transaction
	 *
	 * The transaction stays in the database but disappears from every
	 * active list. Redirects back to the url the request came from so the
	 * filters and page are kept.
	 *
	 * @access public
	 */
	public function display_archive(): void {
		$transaction = $this->get_transaction_from_request();
		if ($transaction !== null && $transaction->archived === null) {
			$transaction->archive();
			Session::set_sticky('finance_transactions_result', [ 'archived' => 1 ]);
		}

		Session::redirect($this->get_back_url());
	}

	/**
	 * Restore an archived transaction
	 *
	 * @access public
	 */
	public function display_restore(): void {
		$transaction = $this->get_transaction_from_request();
		if ($transaction !== null && $transaction->archived !== null) {
			$transaction->restore();
			Session::set_sticky('finance_transactions_result', [ 'restored' => 1 ]);
		}

		Session::redirect($this->get_back_url());
	}

	/**
	 * Show the detail page of a single transaction
	 *
	 * The back url carries the list's query string, so returning keeps the
	 * filters, sorting and page the user came from. Archived transactions
	 * can be viewed and restored from here.
	 *
	 * @access public
	 */
	public function display_detail(): void {
		$this->template = 'finances/detail.twig';
		$template = Template::get();

		// get_by_id throws when the id does not exist, a tampered url must
		// fall back to the list instead of showing an error
		$transaction = null;
		try {
			$transaction = \Bank_Transaction::get_by_id((int) ($_GET['transaction_id'] ?? 0));
		} catch (\Exception $e) {
			$transaction = null;
		}

		if ($transaction === null || $transaction->id === null) {
			Session::redirect('/finances/transactions');
		}

		if (isset($_POST['detail_save']) === true) {
			$this->save_detail($transaction);
		}

		$template->assign('transaction', $transaction);
		$template->assign('back_url', $this->get_back_url());
		$template->assign('categories', \Finance_Category::get_all_active());
	}

	/**
	 * Save the editable fields of the detail page
	 *
	 * Assigning a category clears the incoming flag: for categorized
	 * transactions the direction follows the amount sign, the flag is only
	 * stored for uncategorized ones. The internal transfer checkbox is
	 * stored as 1 or 0; flagged transfers are excluded from the statistics
	 * and the review queue. Redirects back to the detail page so a refresh
	 * does not resubmit the form.
	 *
	 * @access private
	 * @param Bank_Transaction $transaction
	 */
	private function save_detail(\Bank_Transaction $transaction): void {
		$category_id = (int) ($_POST['category_id'] ?? 0);

		if ($category_id > 0) {
			try {
				$category = \Finance_Category::get_by_id($category_id);
			} catch (\Exception $e) {
				$category = null;
			}

			if ($category !== null && $category->id !== null) {
				$transaction->finance_category_id = $category->id;
				$transaction->incoming = null;
				$transaction->finance_rule_id = null;
			}
		} else {
			$transaction->finance_category_id = null;
			$transaction->finance_rule_id = null;
			$transaction->incoming = ((int) ($_POST['incoming'] ?? 0) === 1) ? 1 : 0;
		}

		$transaction->internal = isset($_POST['internal_transfer']) === true ? 1 : 0;

		$transaction->save();

		Session::set_sticky('finance_detail_result', [ 'saved' => true ]);

		$query = [ 'action' => 'detail', 'transaction_id' => $transaction->id ];
		$back_url = $this->get_back_url();
		if ($back_url !== '/finances/transactions') {
			$query['back'] = $back_url;
		}

		Session::redirect('/finances/transactions?' . http_build_query($query));
	}

	/**
	 * Get the amount totals for the current pager filter
	 *
	 * The pager's own conditions (filters, search and any sticky-restored
	 * state) are reused so the sums always match the list on the page.
	 * Incoming and outgoing are summed separately by appending a sign
	 * condition to the pager's amount conditions; the outgoing sum comes
	 * back negative and is reported as a positive amount.
	 *
	 * @access private
	 * @param Skeleton\Pager\Web\Pager $pager
	 * @return array $totals
	 */
	private function get_filter_totals(\Skeleton\Pager\Web\Pager $pager): array {
		$conditions = $pager->get_conditions();

		$incoming_conditions = $conditions;
		$incoming_conditions['amount'][] = new \Skeleton\Pager\Sql\Condition('bank_transaction.amount', '>', 0);

		$outgoing_conditions = $conditions;
		$outgoing_conditions['amount'][] = new \Skeleton\Pager\Sql\Condition('bank_transaction.amount', '<', 0);

		$amount_in = (float) \Bank_Transaction::sum('bank_transaction.amount', $incoming_conditions);
		$amount_out = (float) \Bank_Transaction::sum('bank_transaction.amount', $outgoing_conditions);

		return [
			'count' => (int) $pager->item_count,
			'amount_in' => $amount_in,
			'amount_out' => abs($amount_out),
			'net' => $amount_in + $amount_out,
		];
	}

	/**
	 * Build the transaction pager with every active filter applied
	 *
	 * Every filter is optional: when absent the condition is not added, so
	 * the default view is every (non-internal) transaction of every account.
	 * The one condition that is always present is the internal-transfer
	 * exclusion, which the Transfers filter overrides.
	 *
	 * @access private
	 * @return Skeleton\Pager\Web\Pager $pager
	 */
	private function get_transaction_pager(): \Skeleton\Pager\Web\Pager {
		$pager = new \Skeleton\Pager\Web\Pager('Bank_Transaction');

		// Only active transactions by default; the status filter opens the
		// archived ones so they can be reviewed and restored
		$status_filter = $this->get_status_filter();
		if ($status_filter === 'active') {
			$pager->add_condition('archived', 'IS', null);
		} elseif ($status_filter === 'archived') {
			$pager->add_condition('archived', 'IS NOT', null);
		}

		$account_filter = $this->get_account_filter();
		if ($account_filter !== '') {
			$pager->add_condition('bank_account_id', '=', (int) $account_filter);
		}

		$year_filter = $this->get_year_filter();
		if ($year_filter !== '') {
			$pager->add_condition('value_date', 'BETWEEN', [ $year_filter . '-01-01', $year_filter . '-12-31' ]);
		}

		$date_from = $this->get_date_filter('date_from');
		if ($date_from !== '') {
			$pager->add_condition('value_date', '>=', $date_from);
		}

		$date_to = $this->get_date_filter('date_to');
		if ($date_to !== '') {
			$pager->add_condition('value_date', '<=', $date_to);
		}

		$category_filter = $this->get_category_filter();
		if ($category_filter === 'uncategorized') {
			$pager->add_condition('finance_category_id', 'IS', null);
		} elseif ($category_filter !== '') {
			$pager->add_condition('finance_category_id', '=', (int) $category_filter);
		}

		$direction_filter = $this->get_direction_filter();
		if ($direction_filter === 'in') {
			$pager->add_condition('amount', '>', 0);
		} elseif ($direction_filter === 'out') {
			$pager->add_condition('amount', '<', 0);
		}

		// Internal transfers are hidden unless the filter asks for them: 'all'
		// lists them next to the regular transactions, 'internal' lists
		// nothing but them, and the default shows only the regular ones
		$internal_filter = $this->get_internal_filter();
		if ($internal_filter === 'internal') {
			$pager->add_condition('internal', '=', 1);
		} elseif ($internal_filter !== 'all') {
			$pager->add_condition('internal', '=', 0);
		}

		// The amount bounds are absolute values (a minimum of 100 means a
		// transaction of at least 100 euro). Outgoing amounts are negative
		// in the database, so when the direction filter selects outgoings
		// the bounds are mirrored. Without a direction filter the bounds
		// apply to the signed amount.
		$amount_min = $this->get_amount_filter('amount_min');
		$amount_max = $this->get_amount_filter('amount_max');
		if ($amount_min !== '' || $amount_max !== '') {
			$mirror = $direction_filter === 'out';
			if ($amount_min !== '') {
				$pager->add_condition('amount', $mirror ? '<=' : '>=', $mirror ? -(float) $amount_min : (float) $amount_min);
			}
			if ($amount_max !== '') {
				$pager->add_condition('amount', $mirror ? '>=' : '<=', $mirror ? -(float) $amount_max : (float) $amount_max);
			}
		}

		$search = trim((string) ($_GET['search'] ?? ''));
		if ($search !== '') {
			$pager->set_search($search);
		}

		$pager->add_sort_permission('value_date');
		$pager->add_sort_permission('counterparty_name');
		$pager->add_sort_permission('description');
		$pager->add_sort_permission('amount');
		$pager->add_sort_permission('finance_category.name');
		$pager->add_sort_permission('bank_account.name');
		$pager->set_sort('value_date');
		$pager->set_direction('desc');
		$pager->page();

		return $pager;
	}

	/**
	 * Get the back url of the detail page
	 *
	 * Only urls pointing back into this module are accepted; anything else
	 * falls back to the plain list.
	 *
	 * @access private
	 * @return string $back_url
	 */
	private function get_back_url(): string {
		$back_url = (string) ($_GET['back'] ?? '');
		if (str_starts_with($back_url, '/finances/transactions') === true) {
			return $back_url;
		}

		return '/finances/transactions';
	}

	/**
	 * Get the distinct years holding transactions across all accounts
	 *
	 * Includes the current year so the dropdown always offers it.
	 *
	 * @access private
	 * @return array $years
	 */
	private function get_all_years(): array {
		$database = \Skeleton\Database\Database::get();
		$years = array_merge(
			[ (int) date('Y') ],
			array_map('intval', $database->get_column(
				'SELECT DISTINCT YEAR(`value_date`)
				FROM `bank_transaction`
				WHERE `archived` IS NULL
				AND `value_date` IS NOT NULL
				ORDER BY YEAR(`value_date`) DESC'
			))
		);

		$years = array_unique($years);
		sort($years, SORT_DESC);

		return $years;
	}

	/**
	 * Get the account filter from the url
	 *
	 * Empty means every account. Only ids of active accounts are accepted.
	 *
	 * @access private
	 * @return string $account_filter
	 */
	private function get_account_filter(): string {
		$account_filter = (string) ($_GET['account'] ?? '');
		if ($account_filter === '' || is_numeric($account_filter) === false) {
			return '';
		}

		foreach (\Bank_Account::get_all_uploadable() as $bank_account) {
			if ((int) $bank_account->id === (int) $account_filter) {
				return $account_filter;
			}
		}

		return '';
	}

	/**
	 * Get the year filter from the url
	 *
	 * Empty means every year.
	 *
	 * @access private
	 * @return string $year_filter
	 */
	private function get_year_filter(): string {
		$year_filter = (string) ($_GET['year'] ?? '');
		if ($year_filter === '' || is_numeric($year_filter) === false) {
			return '';
		}

		return $year_filter;
	}

	/**
	 * Get the category filter from the url
	 *
	 * One of '' (all), 'uncategorized' or a category id.
	 *
	 * @access private
	 * @return string $category_filter
	 */
	private function get_category_filter(): string {
		$category_filter = (string) ($_GET['category'] ?? '');
		if ($category_filter === 'uncategorized') {
			return $category_filter;
		}

		if (is_numeric($category_filter) === true && (int) $category_filter > 0) {
			return $category_filter;
		}

		return '';
	}

	/**
	 * Get the direction filter from the url
	 *
	 * One of '' (all), 'in' or 'out'.
	 *
	 * @access private
	 * @return string $direction_filter
	 */
	private function get_direction_filter(): string {
		$direction_filter = (string) ($_GET['direction'] ?? '');
		if (in_array($direction_filter, [ 'in', 'out' ], true) === true) {
			return $direction_filter;
		}

		return '';
	}

	/**
	 * Get the internal transfer filter from the url
	 *
	 * One of '' (hide internal transfers, the default), 'internal' (only
	 * internal transfers) or 'all' (internal transfers included alongside
	 * everything else). 'exclude' is accepted as a synonym of the default
	 * so existing links keep working.
	 *
	 * @access private
	 * @return string $internal_filter
	 */
	private function get_internal_filter(): string {
		$internal_filter = (string) ($_GET['internal'] ?? '');
		if (in_array($internal_filter, [ 'internal', 'all' ], true) === true) {
			return $internal_filter;
		}

		return '';
	}

	/**
	 * Get a date filter from the url
	 *
	 * Only valid Y-m-d dates are accepted, so the pager condition can never
	 * receive arbitrary input.
	 *
	 * @access private
	 * @param string $parameter
	 * @return string $date
	 */
	private function get_date_filter(string $parameter): string {
		$date = (string) ($_GET[$parameter] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return '';
		}

		$date_time = \DateTime::createFromFormat('Y-m-d', $date);
		if ($date_time === false) {
			return '';
		}

		return $date_time->format('Y-m-d');
	}

	/**
	 * Get an amount filter from the url
	 *
	 * Empty means no bound.
	 *
	 * @access private
	 * @param string $parameter
	 * @return string $amount
	 */
	private function get_amount_filter(string $parameter): string {
		$amount = (string) ($_GET[$parameter] ?? '');
		if ($amount === '' || is_numeric($amount) === false) {
			return '';
		}

		return $amount;
	}

	/**
	 * Get the status filter from the url
	 *
	 * One of 'active' (default), 'archived' or 'all'.
	 *
	 * @access private
	 * @return string $status_filter
	 */
	private function get_status_filter(): string {
		$status_filter = (string) ($_GET['status'] ?? 'active');
		if (in_array($status_filter, [ 'active', 'archived', 'all' ], true) === true) {
			return $status_filter;
		}

		return 'active';
	}

	/**
	 * Get the transaction from the posted request
	 *
	 * Returns null when the id is missing or unknown, so archive and
	 * restore silently fall back to the list.
	 *
	 * @access private
	 * @return ?Bank_Transaction $transaction
	 */
	private function get_transaction_from_request(): ?\Bank_Transaction {
		try {
			$transaction = \Bank_Transaction::get_by_id((int) ($_GET['transaction_id'] ?? 0));
		} catch (\Exception $e) {
			return null;
		}

		if ($transaction->id === null) {
			return null;
		}

		return $transaction;
	}
}
