<?php
/**
 * Module Finances
 *
 * Finances area overview: quick statistics, a bank account switcher, the
 * import form and the most recent transactions. The main content area gets
 * maximum width (tiny sidebar) so the data is the focus.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;
use Skeleton\Core\Http\Session;

class Finances extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'finances/overview.twig';

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
	 * Display the finances overview
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();
		$bank_accounts = \Bank_Account::get_all_uploadable();

		$selected_account = $this->get_selected_account($bank_accounts);
		$selected_year = $this->get_selected_year();
		$template->assign('bank_accounts', $bank_accounts);
		$template->assign('selected_account', $selected_account);
		$template->assign('years', $this->get_stat_years($selected_account));
		$template->assign('selected_year', $selected_year);

		if ($selected_account === null) {
			$template->assign('stats', null);
			$template->assign('pager', null);
			$template->assign('uncategorized_count', 0);

			return;
		}

		$template->assign('stats', \Bank_Transaction::get_stats($selected_account->id, $selected_year));
		$template->assign('uncategorized_count', $this->get_uncategorized_count($selected_account->id));

		$pager = $this->get_transaction_pager($selected_account, $selected_year);
		$template->assign('pager', $pager);
		$template->assign('header_value_date', $pager->create_header('Date', 'value_date'));
		$template->assign('header_counterparty_name', $pager->create_header('Counterparty', 'counterparty_name'));
		$template->assign('header_description', $pager->create_header('Description', 'description'));
		$template->assign('header_category', $pager->create_header('Category', 'finance_category.name'));
		$template->assign('header_amount', $pager->create_header('Amount', 'amount'));
		$template->assign('filter_search', $pager->get_search());
		$template->assign('filter_category', $this->get_category_filter());
		$template->assign('filter_direction', $this->get_direction_filter());
		$template->assign('categories', \Finance_Category::get_all_active());
	}

	/**
	 * Display the detail view of a single bank transaction
	 *
	 * @access public
	 */
	public function display_detail(): void {
		$this->template = 'finances/detail.twig';
		$template = Template::get();

		// get_by_id throws when the id does not exist, a tampered url must
		// fall back to the overview instead of showing an error
		$transaction = null;
		try {
			$transaction = \Bank_Transaction::get_by_id((int) ($_GET['transaction_id'] ?? 0));
		} catch (\Exception $e) {
			$transaction = null;
		}

		if ($transaction === null || $transaction->id === null) {
			Session::redirect('/finances');

			return;
		}

		$template->assign('transaction', $transaction);
		$template->assign('back_url', $this->get_detail_back_url());
		$template->assign('categories', \Finance_Category::get_all_active());
	}

	/**
	 * Get the back url of the detail page
	 *
	 * Only urls pointing back into the finances area are accepted; anything
	 * else falls back to the overview.
	 *
	 * @access private
	 * @return string $back_url
	 */
	private function get_detail_back_url(): string {
		$back_url = (string) ($_GET['back'] ?? '');
		if (str_starts_with($back_url, '/finances') === true) {
			return $back_url;
		}

		return '/finances';
	}

	/**
	 * Handle uncategorized transactions
	 *
	 * Shows a pager of all uncategorized transactions across every account.
	 * For each transaction the user can set the incoming/outgoing flag and
	 * assign a category. Internal transfers between own accounts are left
	 * out: they need no category and would otherwise sit in the queue
	 * forever.
	 *
	 * @access public
	 */
	public function display_handle(): void {
		if (isset($_POST['handle_save']) === true) {
			$this->handle_save();
		}

		$this->template = 'finances/handle.twig';
		$template = Template::get();

		$pager = new \Skeleton\Pager\Web\Pager('Bank_Transaction');
		$pager->add_condition('archived', 'IS', null);
		$pager->add_condition('finance_category_id', 'IS', null);
		$pager->add_condition('internal', '=', 0);

		$pager->add_sort_permission('value_date');
		$pager->add_sort_permission('counterparty_name');
		$pager->add_sort_permission('description');
		$pager->add_sort_permission('amount');
		$pager->add_sort_permission('bank_account.name');
		$pager->set_sort('value_date');
		$pager->set_direction('desc');
		$pager->page();

		$template->assign('pager', $pager);
		$template->assign('header_value_date', $pager->create_header('Date', 'value_date'));
		$template->assign('header_counterparty_name', $pager->create_header('Counterparty', 'counterparty_name'));
		$template->assign('header_description', $pager->create_header('Description', 'description'));
		$template->assign('header_amount', $pager->create_header('Amount', 'amount'));
		$template->assign('header_account', $pager->create_header('Account', 'bank_account.name'));
		$template->assign('return_to', $_SERVER['REQUEST_URI']);
		$template->assign('categories', \Finance_Category::get_all_active());
	}

	/**
	 * Save the handle page changes
	 *
	 * Reads the posted transaction data and updates each transaction's
	 * incoming flag and category. When a row was assigned a category
	 * with the "also create rule" checkbox ticked, a fuzzy rule for its
	 * counterparty (or description) is created so future transactions of
	 * the same merchant categorize automatically. Redirects back to the
	 * handle page, preserving the pager state (sorting, page) through
	 * return_to.
	 *
	 * @access private
	 */
	private function handle_save(): void {
		$transactions = $_POST['transactions'] ?? [];
		$saved = 0;
		$rules_created = 0;

		foreach ($transactions as $transaction_id => $data) {
			$transaction = \Bank_Transaction::get_by_id((int) $transaction_id);
			if ($transaction->id === null) {
				continue;
			}

			if (isset($data['incoming']) === true) {
				$transaction->incoming = (int) $data['incoming'];
			}

			$category_id = (int) ($data['category_id'] ?? 0);
			if ($category_id > 0) {
				$category = \Finance_Category::get_by_id($category_id);
				if ($category->id !== null) {
					$transaction->finance_category_id = $category->id;
					$transaction->incoming = null;
					$transaction->finance_rule_id = null;

					if (isset($data['create_rule']) === true) {
						$rules_created += (int) $this->create_teach_rule($transaction, $category);
					}
				}
			}

			$transaction->save();
			$saved++;
		}

		Session::set_sticky('finance_handle_result', [
			'saved' => $saved,
			'rules_created' => $rules_created,
		]);

		$return_to = (string) ($_POST['return_to'] ?? '');
		if (str_starts_with($return_to, '/finances') === false || str_contains($return_to, 'action=handle') === false) {
			$return_to = '/finances?action=handle';
		}

		Session::redirect($return_to);
	}

	/**
	 * Create the teach-loop rule for a manually categorized transaction
	 *
	 * The rule matches fuzzily on the counterparty name (or the
	 * description when no counterparty is stored) and belongs to the
	 * just-assigned category. It is appended at the end of the global
	 * order and never created when an identical active rule already
	 * exists. Returns 1 when a rule was created, 0 otherwise.
	 *
	 * @access private
	 * @param \Bank_Transaction $transaction
	 * @param \Finance_Category $category
	 * @return int $created
	 */
	private function create_teach_rule(\Bank_Transaction $transaction, \Finance_Category $category): int {
		$pattern = trim((string) ($transaction->counterparty_name ?? ''));
		$field = \Finance_Rule::FIELD_COUNTERPARTY_NAME;
		if ($pattern === '') {
			$pattern = trim((string) ($transaction->description ?? ''));
			$field = \Finance_Rule::FIELD_DESCRIPTION;
		}

		if ($pattern === '') {
			return 0;
		}

		if (\Finance_Rule::find_duplicate($pattern, \Finance_Rule::MATCH_FUZZY, (int) $category->id) !== null) {
			return 0;
		}

		$rule = new \Finance_Rule();
		$rule->name = mb_substr($pattern, 0, 80);
		$rule->finance_category_id = (int) $category->id;
		$rule->match_type = \Finance_Rule::MATCH_FUZZY;
		$rule->pattern = $pattern;
		$rule->sort_order = \Finance_Rule::get_next_sort_order();
		$rule->save();
		$rule->set_fields([ $field ]);

		return 1;
	}

	/**
	 * Re-apply the categorization rules to the flagged transactions
	 *
	 * Delegates to Bank_Reindex::run(): the handle page's re-sweep lives
	 * in that component so it can also be run from the server without a
	 * browser session. See the component for the exact guarantees.
	 *
	 * @access public
	 */
	public function display_reindex(): void {
		$result = \Bank_Reindex::run();

		Session::set_sticky('finance_handle_result', $result);
		Session::redirect('/finances?action=handle');
	}

	/**
	 * Preview an uploaded bank export before importing it
	 *
	 * Parses the uploaded file and shows which account it will land in,
	 * including a warning when the file appears to belong to a different
	 * configured account. The parsed rows are held in the session until the
	 * user confirms the import.
	 *
	 * @access public
	 */
	public function display_preview(): void {
		$bank_account = $this->get_posted_account();
		$this->template = 'finances/import_preview.twig';
		$template = Template::get();

		if (isset($_FILES['file']) === false || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			Session::set_sticky('finance_import_result', [ 'error' => 'upload_failed' ]);
			Session::redirect('/finances');

			return;
		}

		try {
			$content = \Bank_Import::read_normalized_csv($_FILES['file']['tmp_name']);
			$rows = \Bank_Import::parse_rows($content);
		} catch (\Exception $e) {
			error_log('Bank import preview failed: ' . $e->getMessage());
			Session::set_sticky('finance_import_result', [ 'error' => 'import_failed' ]);
			Session::redirect('/finances');

			return;
		}

		if (count($rows) === 0) {
			Session::set_sticky('finance_import_result', [ 'error' => 'no_rows' ]);
			Session::redirect('/finances');

			return;
		}

		$_SESSION['finance_import_preview'] = [
			'bank_account_id' => $bank_account->id,
			'rows' => $rows,
		];

		$template->assign('bank_account', $bank_account);
		$template->assign('file_account', \Bank_Import::detect_account($rows));
		$template->assign('row_count', count($rows));
	}

	/**
	 * Import the confirmed bank export
	 *
	 * Runs the import on the rows stored by the preview step. Requiring an
	 * explicit confirm guards against uploading an export without review,
	 * but the account choice is only a fallback: the export's own account
	 * identifier (the IBAN on its rows, via Bank_Import::detect_account)
	 * decides the target when it matches a configured account, so an
	 * export can never land in the wrong account even when the wrong one
	 * was selected at the preview.
	 *
	 * @access public
	 */
	public function display_import(): void {
		$import_preview = $_SESSION['finance_import_preview'] ?? null;
		unset($_SESSION['finance_import_preview']);

		if (is_array($import_preview) === false) {
			Session::set_sticky('finance_import_result', [ 'error' => 'expired' ]);
			Session::redirect('/finances');

			return;
		}

		$bank_account = \Bank_Account::get_by_id((int) $import_preview['bank_account_id']);
		if ($bank_account->id === null || $bank_account->archived !== null) {
			Session::set_sticky('finance_import_result', [ 'error' => 'no_account' ]);
			Session::redirect('/finances');

			return;
		}

		if ($bank_account->is_savings() === true) {
			Session::set_sticky('finance_import_result', [ 'error' => 'savings' ]);
			Session::redirect('/finances');

			return;
		}

		// The export's own account identifier wins over the account chosen
		// at preview: a wrong pick can no longer send a file to the wrong
		// account. When no configured account matches the rows, the chosen
		// account is kept as fallback.
		$detected_account = \Bank_Import::detect_account($import_preview['rows']);
		if ($detected_account !== null) {
			$bank_account = $detected_account;
		}

		$result = \Bank_Import::import_rows($import_preview['rows'], $bank_account);
		Session::set_sticky('finance_import_result', $result);
		Session::redirect('/finances');
	}

	/**
	 * Read and validate the posted bank account
	 *
	 * @access private
	 * @return Bank_Account $bank_account
	 */
	private function get_posted_account(): \Bank_Account {
		if (isset($_POST['bank_account_id']) === false || is_numeric($_POST['bank_account_id']) === false) {
			Session::set_sticky('finance_import_result', [ 'error' => 'no_account' ]);
			Session::redirect('/finances');
		}

		$bank_account = \Bank_Account::get_by_id((int) $_POST['bank_account_id']);
		if ($bank_account->id === null || $bank_account->archived !== null) {
			Session::set_sticky('finance_import_result', [ 'error' => 'no_account' ]);
			Session::redirect('/finances');
		}

		if ($bank_account->is_savings() === true) {
			Session::set_sticky('finance_import_result', [ 'error' => 'savings' ]);
			Session::redirect('/finances');
		}

		return $bank_account;
	}

	/**
	 * Get the bank account selected in the session or url
	 *
	 * Falls back to the first active account.
	 *
	 * @access private
	 * @param array $bank_accounts
	 * @return ?Bank_Account $bank_account
	 */
	private function get_selected_account(array $bank_accounts): ?\Bank_Account {
		if (count($bank_accounts) === 0) {
			return null;
		}

		$requested_id = $_GET['account'] ?? null;
		if ($requested_id !== null && is_numeric($requested_id) === true) {
			foreach ($bank_accounts as $bank_account) {
				if ((int) $bank_account->id === (int) $requested_id) {
					return $bank_account;
				}
			}
		}

		return $bank_accounts[0];
	}

	/**
	 * Count the uncategorized transactions of an account
	 *
	 * Internal transfers are not counted: they carry no category on
	 * purpose and never appear in the review queue.
	 *
	 * @access private
	 * @param int $bank_account_id
	 * @return int $count
	 */
	private function get_uncategorized_count(int $bank_account_id): int {
		$database = \Skeleton\Database\Database::get();
		$count = $database->get_one(
			'SELECT COUNT(*) FROM `bank_transaction`
			WHERE `archived` IS NULL
			AND `bank_account_id` = ?
			AND `finance_category_id` IS NULL
			AND `internal` = 0',
			[ $bank_account_id ]
		);

		return (int) $count;
	}

	/**
	 * Get the year selected in the url
	 *
	 * Defaults to the current year.
	 *
	 * @access private
	 * @return int $year
	 */
	private function get_selected_year(): int {
		if (isset($_GET['year']) === true && is_numeric($_GET['year']) === true) {
			return (int) $_GET['year'];
		}

		return (int) date('Y');
	}

	/**
	 * Build the transaction pager for the selected account and year
	 *
	 * The pager carries the free-text search and the category and direction
	 * filters. The account and the year are always applied: they are the
	 * context of the page, not part of the filter form. Internal transfers
	 * between own accounts are never listed here: they are neither income
	 * nor expense and the archive is the place to review them.
	 *
	 * @access private
	 * @param Bank_Account $bank_account
	 * @param int $year
	 * @return Skeleton\Pager\Web\Pager $pager
	 */
	private function get_transaction_pager(\Bank_Account $bank_account, int $year): \Skeleton\Pager\Web\Pager {
		$pager = new \Skeleton\Pager\Web\Pager('Bank_Transaction');
		$pager->add_condition('archived', 'IS', null);
		$pager->add_condition('internal', '=', 0);
		$pager->add_condition('bank_account_id', '=', $bank_account->id);
		$pager->add_condition('value_date', 'BETWEEN', [ $year . '-01-01', $year . '-12-31' ]);

		$search = trim((string) ($_GET['search'] ?? ''));
		if ($search !== '') {
			$pager->set_search($search);
		}

		$category_filter = $this->get_category_filter();
		if ($category_filter === 'uncategorized') {
			$pager->add_condition('finance_category_id', 'IS', null);
		} elseif (is_numeric($category_filter) === true && (int) $category_filter > 0) {
			$pager->add_condition('finance_category_id', '=', (int) $category_filter);
		}

		$direction_filter = $this->get_direction_filter();
		if ($direction_filter === 'in') {
			$pager->add_condition('amount', '>', 0);
		} elseif ($direction_filter === 'out') {
			$pager->add_condition('amount', '<', 0);
		}

		$pager->add_sort_permission('value_date');
		$pager->add_sort_permission('counterparty_name');
		$pager->add_sort_permission('description');
		$pager->add_sort_permission('amount');
		$pager->add_sort_permission('finance_category.name');
		$pager->set_sort('value_date');
		$pager->set_direction('desc');
		$pager->page();

		return $pager;
	}

	/**
	 * Get the category filter from the url
	 *
	 * One of 'all', 'uncategorized' or a category id.
	 *
	 * @access private
	 * @return string $category_filter
	 */
	private function get_category_filter(): string {
		$category_filter = (string) ($_GET['category'] ?? 'all');
		if ($category_filter === 'uncategorized') {
			return $category_filter;
		}

		if (is_numeric($category_filter) === true && (int) $category_filter > 0) {
			return $category_filter;
		}

		return 'all';
	}

	/**
	 * Get the direction filter from the url
	 *
	 * One of 'all', 'in' or 'out'.
	 *
	 * @access private
	 * @return string $direction_filter
	 */
	private function get_direction_filter(): string {
		$direction_filter = (string) ($_GET['direction'] ?? 'all');
		if (in_array($direction_filter, [ 'in', 'out' ], true) === true) {
			return $direction_filter;
		}

		return 'all';
	}

	/**
	 * Get the distinct years that hold transactions for an account
	 *
	 * Includes the current year so the dropdown always offers it, even when
	 * no transaction exists yet.
	 *
	 * @access private
	 * @param ?Bank_Account $bank_account
	 * @return array $years
	 */
	private function get_stat_years(?\Bank_Account $bank_account): array {
		$years = [ (int) date('Y') ];

		if ($bank_account === null) {
			return $years;
		}

		$database = \Skeleton\Database\Database::get();
		$years = array_merge(
			$years,
			array_map('intval', $database->get_column(
				'SELECT DISTINCT YEAR(`value_date`)
				FROM `bank_transaction`
				WHERE `archived` IS NULL
				AND `bank_account_id` = ?
				AND `value_date` IS NOT NULL
				ORDER BY YEAR(`value_date`) DESC',
				[ $bank_account->id ]
			))
		);

		$years = array_unique($years);
		sort($years, SORT_DESC);

		return $years;
	}
}
