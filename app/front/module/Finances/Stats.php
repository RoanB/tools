<?php
/**
 * Module Finances Stats
 *
 * Statistics dashboard for the finances area: totals, category breakdowns
 * (donut, per-month stacked, incoming versus outgoing) and the monthly
 * evolution. The account, year and month filters are the context of the
 * page and apply to every chart at once; a month selection drops the
 * month-by-month charts.
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
	 * The account, year and month filters are the context of the page and
	 * apply to every chart at once. A month filter is only meaningful
	 * together with a year; the monthly evolution charts are dropped
	 * when a single month is selected (they have nothing to compare
	 * against).
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();
		$bank_accounts = \Bank_Account::get_all_uploadable();

		$account_filter = $this->get_account_filter($bank_accounts);
		$year_filter = $this->get_year_filter();
		$month_filter = $this->get_month_filter($year_filter);

		$bank_account_id = $account_filter === '' ? null : (int) $account_filter;
		$year = $year_filter === '' ? null : (int) $year_filter;
		$month = $month_filter === '' ? null : (int) $month_filter;

		$template->assign('bank_accounts', $bank_accounts);
		$template->assign('years', $this->get_all_years());
		$template->assign('months', [
			1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
			5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
			9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
		]);
		$template->assign('filter_account', $account_filter);
		$template->assign('filter_year', $year_filter);
		$template->assign('filter_month', $month_filter);

		$template->assign('totals', \Bank_Stats::get_totals($bank_account_id, $year, $month));
		$template->assign('biggest', \Bank_Stats::get_biggest_outgoing($bank_account_id, $year, $month));
		$template->assign('counterparties', \Bank_Stats::get_top_counterparties($bank_account_id, $year, $month));

		$category_totals = \Bank_Stats::get_category_totals($bank_account_id, $year, $month);
		$template->assign('category_totals', $category_totals);

		$total_out = 0.0;
		foreach ($category_totals as $category_total) {
			$total_out += $category_total['amount_out'];
		}

		// The month-by-month charts make no sense for a single month
		$chart_data = [
			'year' => $year,
			'category_totals' => \Bank_Stats::get_category_totals($bank_account_id, $year, $month),
			'total_out' => $total_out,
		];
		if ($month === null) {
			$chart_data['monthly'] = \Bank_Stats::get_monthly($bank_account_id, $year);
			$chart_data['category_monthly'] = \Bank_Stats::get_category_monthly($bank_account_id, $year);
		}

		$template->assign('chart_data', $chart_data);
	}

	/**
	 * Get the month filter from the url
	 *
	 * One of '' (all months) or 1..12. A month is only applied when a
	 * specific year is selected: "all years" collapses the filter.
	 *
	 * @access private
	 * @param string $year_filter
	 * @return string $month_filter
	 */
	private function get_month_filter(string $year_filter): string {
		if ($year_filter === '' || array_key_exists('month', $_GET) === false) {
			return '';
		}

		$month_filter = (string) $_GET['month'];
		if (is_numeric($month_filter) === false || (int) $month_filter < 1 || (int) $month_filter > 12) {
			return '';
		}

		return (string) (int) $month_filter;
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
