<?php
/**
 * Bank_Stats
 *
 * Aggregation queries behind the finances statistics page. Every method
 * takes an optional bank account id and year and only considers active
 * (non-archived) transactions. Incoming and outgoing amounts are summed
 * separately so outgoings never cancel out the incoming column. Internal
 * transfers between own accounts are excluded from every aggregation: they
 * are neither income nor expense and would otherwise be double counted.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

use Skeleton\Database\Database;

class Bank_Stats {

	/**
	 * The grey used for transactions without a category
	 *
	 * @var string $uncategorized_color
	 */
	const UNCATEGORIZED_COLOR = '#6b737d';

	/**
	 * Get the total amounts for the given filters
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @return array $totals
	 */
	public static function get_totals(?int $bank_account_id = null, ?int $year = null): array {
		$database = Database::get();
		$row = $database->get_row(
			'SELECT
				COUNT(*) AS `transaction_count`,
				COALESCE(SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END), 0) AS `amount_in`,
				COALESCE(SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END), 0) AS `amount_out`,
				COALESCE(SUM(CASE WHEN `finance_category_id` IS NULL THEN 1 ELSE 0 END), 0) AS `uncategorized`
			FROM `bank_transaction`
			' . self::get_where($bank_account_id, $year),
			self::get_params($bank_account_id, $year)
		);

		return [
			'transaction_count' => (int) $row['transaction_count'],
			'amount_in' => (float) $row['amount_in'],
			'amount_out' => (float) $row['amount_out'],
			'uncategorized' => (int) $row['uncategorized'],
			'net' => (float) $row['amount_in'] - (float) $row['amount_out'],
		];
	}

	/**
	 * Get the internal transfer totals for the given filters
	 *
	 * Only outgoing legs are counted: every transfer between two own
	 * accounts has exactly one, so both the count and the total amount
	 * describe the number of transfers, not the number of rows.
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @return array $transfers
	 */
	public static function get_transfers(?int $bank_account_id = null, ?int $year = null): array {
		$database = Database::get();
		$where = 'WHERE `archived` IS NULL AND `internal` = 1';
		$params = [];

		if ($bank_account_id !== null) {
			$where .= ' AND `bank_account_id` = ?';
			$params[] = $bank_account_id;
		}

		if ($year !== null) {
			$where .= ' AND YEAR(`value_date`) = ?';
			$params[] = $year;
		}

		$row = $database->get_row(
			'SELECT
				COUNT(*) AS `transfer_count`,
				COALESCE(SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END), 0) AS `total_moved`
			FROM `bank_transaction`
			' . $where,
			$params
		);

		return [
			'count' => (int) $row['transfer_count'],
			'total' => (float) $row['total_moved'],
		];
	}

	/**
	 * Get the monthly incoming, outgoing and net amounts
	 *
	 * Rows come back ordered by period (YYYY-MM). When a year is given the
	 * result is zero-filled for the twelve months of that year so charts
	 * show a continuous axis.
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @return array $monthly
	 */
	public static function get_monthly(?int $bank_account_id = null, ?int $year = null): array {
		$database = Database::get();
		$rows = $database->get_all(
			'SELECT DATE_FORMAT(`value_date`, \'%Y-%m\') AS `period`,
				COALESCE(SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END), 0) AS `amount_in`,
				COALESCE(SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END), 0) AS `amount_out`
			FROM `bank_transaction`
			' . self::get_where($bank_account_id, $year) . '
			GROUP BY `period`
			ORDER BY `period`',
			self::get_params($bank_account_id, $year)
		);

		$monthly = [];
		foreach ($rows as $row) {
			$monthly[$row['period']] = [
				'period' => $row['period'],
				'amount_in' => (float) $row['amount_in'],
				'amount_out' => (float) $row['amount_out'],
				'net' => (float) $row['amount_in'] - (float) $row['amount_out'],
			];
		}

		if ($year === null) {
			return array_values($monthly);
		}

		$filled = [];
		for ($month = 1; $month <= 12; $month++) {
			$period = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
			if (isset($monthly[$period]) === true) {
				$filled[] = $monthly[$period];
			} else {
				$filled[] = [
					'period' => $period,
					'amount_in' => 0.0,
					'amount_out' => 0.0,
					'net' => 0.0,
				];
			}
		}

		return $filled;
	}

	/**
	 * Get the per-category totals
	 *
	 * Transactions without a category are grouped under an Uncategorized
	 * row. The rows come back ordered by outgoing amount, highest first.
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @return array $category_totals
	 */
	public static function get_category_totals(?int $bank_account_id = null, ?int $year = null): array {
		$database = Database::get();
		$rows = $database->get_all(
			'SELECT
				`bt`.`finance_category_id` AS `category_id`,
				`fc`.`name` AS `category_name`,
				`fc`.`color` AS `category_color`,
				COUNT(*) AS `transaction_count`,
				COALESCE(SUM(CASE WHEN `bt`.`amount` > 0 THEN `bt`.`amount` ELSE 0 END), 0) AS `amount_in`,
				COALESCE(SUM(CASE WHEN `bt`.`amount` < 0 THEN -`bt`.`amount` ELSE 0 END), 0) AS `amount_out`
			FROM `bank_transaction` `bt`
			LEFT JOIN `finance_category` `fc` ON `fc`.`id` = `bt`.`finance_category_id`
			' . self::get_where($bank_account_id, $year, 'bt') . '
			GROUP BY `bt`.`finance_category_id`, `fc`.`name`, `fc`.`color`
			ORDER BY `amount_out` DESC',
			self::get_params($bank_account_id, $year)
		);

		$category_totals = [];
		foreach ($rows as $row) {
			$name = $row['category_name'];
			if ($name === null) {
				$name = 'Uncategorized';
			}

			$category_totals[] = [
				'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
				'name' => $name,
				'color' => self::resolve_color($row['category_color']),
				'transaction_count' => (int) $row['transaction_count'],
				'amount_in' => (float) $row['amount_in'],
				'amount_out' => (float) $row['amount_out'],
				'net' => (float) $row['amount_in'] - (float) $row['amount_out'],
			];
		}

		return $category_totals;
	}

	/**
	 * Get the monthly outgoing amounts per category, chart-ready
	 *
	 * The result is pivoted: periods holds every YYYY-MM label and series
	 * holds one entry per category with a value per period (zero when the
	 * category had no outgoing transactions that month). Only the top
	 * categories by total outgoing amount are returned, the rest is folded
	 * into an Other entry so the stacked chart stays readable.
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @param int $top_categories
	 * @return array $category_monthly
	 */
	public static function get_category_monthly(?int $bank_account_id = null, ?int $year = null, int $top_categories = 8): array {
		$database = Database::get();
		$rows = $database->get_all(
			'SELECT
				DATE_FORMAT(`bt`.`value_date`, \'%Y-%m\') AS `period`,
				COALESCE(`fc`.`name`, \'Uncategorized\') AS `category_name`,
				`fc`.`color` AS `category_color`,
				SUM(-`bt`.`amount`) AS `amount_out`
			FROM `bank_transaction` `bt`
			LEFT JOIN `finance_category` `fc` ON `fc`.`id` = `bt`.`finance_category_id`
			' . self::get_where($bank_account_id, $year, 'bt') . '
			AND `bt`.`amount` < 0
			GROUP BY `period`, `category_name`, `category_color`
			ORDER BY `period`',
			self::get_params($bank_account_id, $year)
		);

		$periods = [];
		$category_totals = [];
		foreach ($rows as $row) {
			$period = $row['period'];
			$name = $row['category_name'];
			if (isset($periods[$period]) === false) {
				$periods[$period] = true;
			}

			if (isset($category_totals[$name]) === false) {
				$category_totals[$name] = [
					'total' => 0.0,
					'color' => self::resolve_color($row['category_color']),
				];
			}

			$category_totals[$name]['total'] += (float) $row['amount_out'];
		}

		$top_names = array_keys($category_totals);
		usort($top_names, function($a, $b) use ($category_totals) {
			return $category_totals[$b]['total'] <=> $category_totals[$a]['total'];
		});

		$series_names = array_slice($top_names, 0, $top_categories);
		$other_names = array_slice($top_names, $top_categories);

		ksort($periods);
		$periods = array_keys($periods);

		if ($year !== null) {
			$periods = [];
			for ($month = 1; $month <= 12; $month++) {
				$periods[] = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
			}
		}

		$series = [];
		foreach ($series_names as $name) {
			$series[$name] = [
				'name' => $name,
				'color' => $category_totals[$name]['color'],
				'data' => [],
			];
		}

		if (count($other_names) > 0) {
			$series['Other'] = [
				'name' => 'Other',
				'color' => self::UNCATEGORIZED_COLOR,
				'data' => [],
			];
		}

		$values = [];
		foreach ($rows as $row) {
			$name = $row['category_name'];
			if (in_array($name, $series_names, true) === false) {
				$name = 'Other';
			}

			$values[$name][$row['period']] = (float) $row['amount_out'];
		}

		foreach ($series as $name => $entry) {
			foreach ($periods as $period) {
				$series[$name]['data'][] = $values[$name][$period] ?? 0.0;
			}
		}

		return [
			'periods' => array_values($periods),
			'series' => array_values($series),
		];
	}

	/**
	 * Get the top counterparties by outgoing amount
	 *
	 * Groups the transactions of the given filters on the counterparty
	 * name and returns those with the largest total outgoing amount.
	 * Counterparties that only receive money are ranked last.
	 *
	 * @access public
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @param int $limit
	 * @return array $counterparties
	 */
	public static function get_top_counterparties(?int $bank_account_id = null, ?int $year = null, int $limit = 10): array {
		$database = Database::get();
		$rows = $database->get_all(
			'SELECT
				`counterparty_name`,
				COUNT(*) AS `transaction_count`,
				COALESCE(SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END), 0) AS `amount_in`,
				COALESCE(SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END), 0) AS `amount_out`
			FROM `bank_transaction`
			' . self::get_where($bank_account_id, $year) . '
			AND `counterparty_name` IS NOT NULL
			AND `counterparty_name` <> \'\'
			GROUP BY `counterparty_name`
			ORDER BY `amount_out` DESC, `amount_in` DESC
			LIMIT ' . $limit,
			self::get_params($bank_account_id, $year)
		);

		$counterparties = [];
		foreach ($rows as $row) {
			$counterparties[] = [
				'name' => $row['counterparty_name'],
				'transaction_count' => (int) $row['transaction_count'],
				'amount_in' => (float) $row['amount_in'],
				'amount_out' => (float) $row['amount_out'],
				'net' => (float) $row['amount_in'] - (float) $row['amount_out'],
			];
		}

		return $counterparties;
	}

	/**
	 * Build the where clause for the given filters
	 *
	 * The optional alias prefixes every column so the same clause works on
	 * plain queries and on queries joining the category table. Internal
	 * transfers between own accounts are always excluded.
	 *
	 * @access private
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @param string $alias
	 * @return string $where
	 */
	private static function get_where(?int $bank_account_id, ?int $year, string $alias = ''): string {
		$prefix = '';
		if ($alias !== '') {
			$prefix = '`' . $alias . '`.';
		}

		$where = 'WHERE ' . $prefix . '`archived` IS NULL';
		$where .= ' AND ' . $prefix . '`internal` = 0';
		$where .= ' AND ' . $prefix . '`value_date` IS NOT NULL';

		if ($bank_account_id !== null) {
			$where .= ' AND ' . $prefix . '`bank_account_id` = ?';
		}

		if ($year !== null) {
			$where .= ' AND YEAR(' . $prefix . '`value_date`) = ?';
		}

		return $where;
	}

	/**
	 * Get the query parameters for the given filters
	 *
	 * @access private
	 * @param ?int $bank_account_id
	 * @param ?int $year
	 * @return array $params
	 */
	private static function get_params(?int $bank_account_id, ?int $year): array {
		$params = [];

		if ($bank_account_id !== null) {
			$params[] = $bank_account_id;
		}

		if ($year !== null) {
			$params[] = $year;
		}

		return $params;
	}

	/**
	 * Resolve a stored category color to a hex value
	 *
	 * Accepts a hex color and the legacy color names, which are translated
	 * through the category model. Anything else falls back to the
	 * uncategorized grey.
	 *
	 * @access private
	 * @param ?string $color
	 * @return string $color
	 */
	private static function resolve_color(?string $color): string {
		$color = strtolower(trim((string) $color));
		if (preg_match('/^#[0-9a-f]{6}$/', $color) === 1) {
			return $color;
		}

		if (isset(\Finance_Category::LEGACY_COLORS[$color]) === true) {
			return \Finance_Category::LEGACY_COLORS[$color];
		}

		return self::UNCATEGORIZED_COLOR;
	}
}
