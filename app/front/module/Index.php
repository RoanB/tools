<?php
/**
 * Module Index
 *
 * Authenticated dashboard.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;

class Index extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'index.twig';

	/**
	 * Secure access
	 *
	 * Require an authenticated user.
	 *
	 * @access public
	 * @return bool $allowed
	 */
	public function secure(): bool {
		return isset($_SESSION['user_id']);
	}

	/**
	 * Display method
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();
		$selected_year = $this->get_selected_year();
		$template->assign('finance_stats', \Bank_Transaction::get_all_stats($selected_year));
		$template->assign('finance_account_count', count(\Bank_Account::get_all_active()));
		$template->assign('year', $selected_year);
		$template->assign('years', $this->get_stat_years());
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
	 * Get the distinct years that hold transactions
	 *
	 * Includes the current year so the dropdown always offers it, even when
	 * no transaction exists yet.
	 *
	 * @access private
	 * @return array $years
	 */
	private function get_stat_years(): array {
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
}
