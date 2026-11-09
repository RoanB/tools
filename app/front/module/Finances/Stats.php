<?php
/**
 * Module Finances Stats
 *
 * Statistics dashboard for the finances area: totals, category breakdowns
 * (donut, per-month stacked, incoming versus outgoing) and the monthly
 * evolution. The account and year filters are the context of the page and
 * apply to every chart at once.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module\Finances;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;

class Stats extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'finances/stats.twig';

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
	 * Display the statistics dashboard
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();
		$bank_accounts = \Bank_Account::get_all_uploadable();

		$account_filter = $this->get_account_filter($bank_accounts);
		$year_filter = $this->get_year_filter();

		$bank_account_id = $account_filter === '' ? null : (int) $account_filter;
		$year = $year_filter === '' ? null : (int) $year_filter;

		$template->assign('bank_accounts', $bank_accounts);
		$template->assign('years', $this->get_all_years());
		$template->assign('filter_account', $account_filter);
		$template->assign('filter_year', $year_filter);

		$template->assign('totals', \Bank_Stats::get_totals($bank_account_id, $year));
		$template->assign('transfers', \Bank_Stats::get_transfers($bank_account_id, $year));
		$template->assign('counterparties', \Bank_Stats::get_top_counterparties($bank_account_id, $year));

		$category_totals = \Bank_Stats::get_category_totals($bank_account_id, $year);
		$template->assign('category_totals', $category_totals);

		$total_out = 0.0;
		foreach ($category_totals as $category_total) {
			$total_out += $category_total['amount_out'];
		}

		$template->assign('chart_data', [
			'year' => $year,
			'monthly' => \Bank_Stats::get_monthly($bank_account_id, $year),
			'category_totals' => \Bank_Stats::get_category_totals($bank_account_id, $year),
			'category_monthly' => \Bank_Stats::get_category_monthly($bank_account_id, $year),
			'total_out' => $total_out,
		]);
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
	 * @param array $bank_accounts
	 * @return string $account_filter
	 */
	private function get_account_filter(array $bank_accounts): string {
		$account_filter = (string) ($_GET['account'] ?? '');
		if ($account_filter === '' || is_numeric($account_filter) === false) {
			return '';
		}

		foreach ($bank_accounts as $bank_account) {
			if ((int) $bank_account->id === (int) $account_filter) {
				return $account_filter;
			}
		}

		return '';
	}

	/**
	 * Get the year filter from the url
	 *
	 * Defaults to the current year; an explicitly empty value selects
	 * every year.
	 *
	 * @access private
	 * @return string $year_filter
	 */
	private function get_year_filter(): string {
		if (array_key_exists('year', $_GET) === false) {
			return date('Y');
		}

		$year_filter = (string) $_GET['year'];
		if ($year_filter === '' || is_numeric($year_filter) === false) {
			return '';
		}

		return $year_filter;
	}
}
