<?php
/**
 * Module Finances Settings
 *
 * Finances configuration: budget categories, their auto-link rules and the
 * bank accounts whose exports are imported. The full sidebar is the primary
 * navigation for this view.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module\Finances;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;
use Skeleton\Core\Http\Session;

class Settings extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'finances/settings/overview.twig';

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
	 * Display the settings overview (categories)
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();

		$pager = new \Skeleton\Pager\Web\Pager('Finance_Category');
		$pager->add_condition('archived', 'IS', null);

		$search = trim((string) ($_GET['search'] ?? ''));
		if ($search !== '') {
			$pager->set_search($search);
		}

		$pager->add_sort_permission('name');
		$pager->add_sort_permission('created');
		$pager->set_sort('name');
		$pager->set_direction('asc');
		$pager->page();

		$template->assign('pager', $pager);
		$template->assign('header_name', $pager->create_header('Name', 'name'));
		$template->assign('filter_search', $search);
	}

	/**
	 * Show the rules overview
	 *
	 * Lists every active rule in its global position. The list is
	 * drag-and-drop sortable: dropping a rule saves its new position
	 * immediately. The categorizer applies the rules strictly from top
	 * to bottom in this order and the first match wins, so a rule lower
	 * in the list can never override the category of a rule above it.
	 *
	 * @access public
	 */
	public function display_rules(): void {
		$this->template = 'finances/settings/rules.twig';
		$template = Template::get();

		$rules = \Finance_Rule::get_all_active();

		// The category badge of every rule is shown in the template; the
		// model load is lazy, so preload the categories in one pass
		$category_ids = [];
		foreach ($rules as $rule) {
			$category_ids[(int) $rule->finance_category_id] = true;
		}

		$categories = [];
		if (count($category_ids) > 0) {
			foreach (\Finance_Category::get_by_ids(array_keys($category_ids)) as $category) {
				$categories[(int) $category->id] = $category;
			}
		}

		$template->assign('rules', $rules);
		$template->assign('categories', $categories);
		$template->assign('audit_counts', \Bank_Rule_Analysis::get_audit_counts());
	}

	/**
	 * Show the rule conflict report
	 *
	 * Read-only simulation of the current rule set over every stored
	 * non-internal transaction: which rules never win (dead), which
	 * rules shadow each other and how many transactions match more than
	 * one rule. Helps the user find the over-eager patterns that
	 * categorize transactions into the wrong category.
	 *
	 * @access public
	 */
	public function display_rule_conflicts(): void {
		$this->template = 'finances/settings/rule_conflicts.twig';
		$template = Template::get();

		$report = \Bank_Rule_Analysis::analyze();

		$rules_by_id = [];
		foreach (\Finance_Rule::get_all_active() as $rule) {
			$rules_by_id[(int) $rule->id] = $rule;
		}

		$template->assign('report', $report);
		$template->assign('rules_by_id', $rules_by_id);
	}

	/**
	 * Merge a category into another one
	 *
	 * GET shows the form with the source category and a target select;
	 * the POST (merge_save) moves all transactions and rules to the
	 * target and archives the source. Merging into itself is rejected
	 * with a sticky error.
	 *
	 * @access public
	 */
	public function display_category_merge(): void {
		$category = $this->get_category_from_request();
		if ($category === null) {
			Session::redirect('/finances/settings');
		}

		$targets = [];
		foreach (\Finance_Category::get_all_active() as $target) {
			if ((int) $target->id !== (int) $category->id) {
				$targets[(int) $target->id] = $target;
			}
		}

		if (isset($_POST['merge_save']) === true) {
			$target = $targets[(int) ($_POST['target_id'] ?? 0)] ?? null;
			if ($target === null) {
				Session::set_sticky('finance_settings_result', [ 'error' => 'same' ]);
				Session::redirect('/finances/settings?action=category_merge&category_id=' . $category->id);
			}

			$result = $category->merge_into($target);
			Session::set_sticky('finance_settings_result', [
				'merged_transactions' => $result['transactions'],
				'merged_rules' => $result['rules'],
				'merged_source' => $category->name,
				'merged_target' => $target->name,
			]);

			Session::redirect('/finances/settings');
		}

		$this->template = 'finances/settings/category_merge.twig';
		$template = Template::get();
		$template->assign('category', $category);
		$template->assign('targets', $targets);
		$template->assign('transaction_count', $category->get_transaction_count());
		$template->assign('rule_count', count($category->get_rules()));
	}

	/**
	 * Save the rule order posted by the sortable rules list
	 *
	 * The posted `order` field holds the rule ids top-to-bottom; every
	 * rule gets its index as global sort_order. Unknown and archived
	 * rules are skipped. Redirects back to the rules list.
	 *
	 * @access public
	 */
	public function display_rule_order(): void {
		$posted = (array) ($_POST['order'] ?? []);

		$rules = [];
		foreach ($posted as $value) {
			if (is_numeric($value) === false || (int) $value < 1) {
				continue;
			}

			try {
				$rule = \Finance_Rule::get_by_id((int) $value);
			} catch (\Exception $e) {
				continue;
			}

			if ($rule->id !== null && $rule->archived === null) {
				$rules[] = $rule;
			}
		}

		$sort_order = 1;
		foreach ($rules as $rule) {
			$rule->sort_order = $sort_order;
			$rule->save();
			$sort_order++;
		}

		Session::redirect('/finances/settings?action=rules');
	}

	/**
	 * Create a category
	 *
	 * @access public
	 */
	public function display_category_new(): void {
		$category = new \Finance_Category();

		// A fresh model object has no properties assigned yet: reading them
		// in the template throws, so initialize the form fields first
		$category->name = '';
		$category->color = '';

		$errors = $this->save_category($category);
		if ($errors === null) {
			return;
		}

		$this->template = 'finances/settings/category_form.twig';
		$template = Template::get();
		$template->assign('category', $category);
		$template->assign('errors', $errors);
		$template->assign('color_value', $category->get_color() ?? '#4c8c4a');
	}

	/**
	 * Edit a category
	 *
	 * @access public
	 */
	public function display_category_edit(): void {
		$category = $this->get_category_from_request();
		if ($category === null) {
			Session::redirect('/finances/settings');
		}

		$errors = $this->save_category($category);
		if ($errors === null) {
			return;
		}

		$this->template = 'finances/settings/category_form.twig';
		$template = Template::get();
		$template->assign('category', $category);
		$template->assign('errors', $errors);
		$template->assign('color_value', $category->get_color() ?? '#4c8c4a');
	}

	/**
	 * Delete a category
	 *
	 * @access public
	 */
	public function display_category_delete(): void {
		$category = $this->get_category_from_request();
		if ($category === null) {
			Session::redirect('/finances/settings');
		}

		$category->archive();
		Session::redirect('/finances/settings');
	}

	/**
	 * Create a rule
	 *
	 * @access public
	 */
	public function display_rule_new(): void {
		$rule = new \Finance_Rule();

		// A fresh model object has no properties assigned yet: reading them
		// in the template throws, so initialize the form fields first
		$rule->name = '';
		$rule->finance_category_id = 0;
		$rule->match_type = '';
		$rule->pattern = '';
		$rule->sort_order = 0;

		[$errors, $selected_fields] = $this->save_rule($rule);
		if ($errors === null) {
			return;
		}

		$this->render_rule_form($rule, $selected_fields, $errors);
	}

	/**
	 * Edit a rule
	 *
	 * @access public
	 */
	public function display_rule_edit(): void {
		$rule = $this->get_rule_from_request();
		if ($rule === null) {
			Session::redirect('/finances/settings?action=rules');
		}

		$selected_fields = $rule->get_selected_fields();
		[$errors, $selected_fields] = $this->save_rule($rule, $selected_fields);
		if ($errors === null) {
			return;
		}

		$this->render_rule_form($rule, $selected_fields, $errors);
	}

	/**
	 * Delete a rule
	 *
	 * @access public
	 */
	public function display_rule_delete(): void {
		$rule = $this->get_rule_from_request();
		if ($rule === null) {
			Session::redirect('/finances/settings?action=rules');
		}

		$rule->archive();
		Session::redirect('/finances/settings?action=rules');
	}

	/**
	 * Show the bank accounts overview
	 *
	 * @access public
	 */
	public function display_bank_accounts(): void {
		$this->template = 'finances/settings/bank_accounts.twig';
		$template = Template::get();

		$pager = new \Skeleton\Pager\Web\Pager('Bank_Account');
		$pager->add_condition('archived', 'IS', null);

		$search = trim((string) ($_GET['search'] ?? ''));
		if ($search !== '') {
			$pager->set_search($search);
		}

		$pager->add_sort_permission('name');
		$pager->add_sort_permission('iban');
		$pager->add_sort_permission('created');
		$pager->set_sort('name');
		$pager->set_direction('asc');
		$pager->page();

		$template->assign('pager', $pager);
		$template->assign('header_name', $pager->create_header('Name', 'name'));
		$template->assign('header_iban', $pager->create_header('IBAN', 'iban'));
		$template->assign('filter_search', $search);
	}

	/**
	 * Create a bank account
	 *
	 * @access public
	 */
	public function display_bank_account_new(): void {
		$bank_account = new \Bank_Account();

		// A fresh model object has no properties assigned yet: reading them
		// in the template throws, so initialize the form fields first
		$bank_account->name = '';
		$bank_account->iban = '';
		$bank_account->savings = 0;

		$errors = $this->save_bank_account($bank_account);
		if ($errors === null) {
			return;
		}

		$this->template = 'finances/settings/bank_account_form.twig';
		$template = Template::get();
		$template->assign('bank_account', $bank_account);
		$template->assign('errors', $errors);
	}

	/**
	 * Edit a bank account
	 *
	 * @access public
	 */
	public function display_bank_account_edit(): void {
		$bank_account = $this->get_bank_account_from_request();
		if ($bank_account === null) {
			Session::redirect('/finances/settings?action=bank_accounts');
		}

		$errors = $this->save_bank_account($bank_account);
		if ($errors === null) {
			return;
		}

		$this->template = 'finances/settings/bank_account_form.twig';
		$template = Template::get();
		$template->assign('bank_account', $bank_account);
		$template->assign('errors', $errors);
	}

	/**
	 * Delete a bank account
	 *
	 * @access public
	 */
	public function display_bank_account_delete(): void {
		$bank_account = $this->get_bank_account_from_request();
		if ($bank_account === null) {
			Session::redirect('/finances/settings?action=bank_accounts');
		}

		$bank_account->archive();
		Session::redirect('/finances/settings?action=bank_accounts');
	}

	/**
	 * Save a category from the posted data
	 *
	 * The posted values are stored on the object so the form can repopulate
	 * after a validation error. Returns null on success (after redirect), or
	 * the list of errors.
	 *
	 * @access private
	 * @param \Finance_Category $category
	 * @return ?array $errors
	 */
	private function save_category(\Finance_Category $category): ?array {
		if (isset($_POST['save']) === false) {
			return [];
		}

		$name = trim((string) ($_POST['name'] ?? ''));
		$color = strtolower(trim((string) ($_POST['color'] ?? '')));
		if (isset($_POST['color_none']) === true) {
			$color = '';
		}

		$category->name = $name;
		$category->color = $color;

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'mandatory';
		}

		if ($color !== '' && preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
			$errors['color'] = 'invalid';
		}

		if (count($errors) > 0) {
			return $errors;
		}

		$category->save();

		Session::redirect('/finances/settings');
	}

	/**
	 * Save a rule from the posted data
	 *
	 * The posted values are stored on the rule object so the form can
	 * repopulate after a validation error. Returns [ null, $selected_fields ]
	 * on success (after redirect), or [ $errors, $selected_fields ].
	 *
	 * @access private
	 * @param \Finance_Rule $rule
	 * @param array $selected_fields
	 * @return array $result
	 */
	private function save_rule(\Finance_Rule $rule, array $selected_fields = []): array {
		if (isset($_POST['save']) === false) {
			return [ [], $selected_fields ];
		}

		$name = trim((string) ($_POST['name'] ?? ''));
		$category_id = (int) ($_POST['category_id'] ?? 0);
		$match_type = (string) ($_POST['match_type'] ?? '');
		$pattern = (string) ($_POST['pattern'] ?? '');
		$selected_fields = array_map('strval', (array) ($_POST['fields'] ?? []));

		$rule->finance_category_id = $category_id;
		$rule->name = $name;
		$rule->match_type = $match_type;
		$rule->pattern = $pattern;

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'mandatory';
		}

		// get_by_id throws when the category does not exist, an empty or
		// unknown category is a validation error for the form
		$category = null;
		if ($category_id > 0) {
			try {
				$category = \Finance_Category::get_by_id($category_id);
			} catch (\Exception $e) {
				$category = null;
			}
		}

		if ($category === null) {
			$errors['category_id'] = 'invalid';
		}

		if (array_key_exists($match_type, \Finance_Rule::get_match_types()) === false) {
			$errors['match_type'] = 'invalid';
		}

		if ($pattern === '') {
			$errors['pattern'] = 'mandatory';
		}

		if (count($selected_fields) === 0) {
			$errors['fields'] = 'mandatory';
		}

		if (\Finance_Rule::MATCH_REGEX === $match_type) {
			if (@preg_match($pattern, '') === false) {
				$errors['pattern'] = 'invalid_regex';
			}
		}

		if (count($errors) > 0) {
			return [ $errors, $selected_fields ];
		}

		if (empty($rule->id) === true) {
			$rule->sort_order = \Finance_Rule::get_next_sort_order();
		}

		$rule->save();
		$rule->set_fields($selected_fields);

		Session::redirect('/finances/settings?action=rules');
	}

	/**
	 * Save a bank account from the posted data
	 *
	 * The posted values are stored on the object so the form can repopulate
	 * after a validation error. Returns null on success (after redirect), or
	 * the list of errors.
	 *
	 * @access private
	 * @param \Bank_Account $bank_account
	 * @return ?array $errors
	 */
	private function save_bank_account(\Bank_Account $bank_account): ?array {
		if (isset($_POST['save']) === false) {
			return [];
		}

		$name = trim((string) ($_POST['name'] ?? ''));
		$iban = trim((string) ($_POST['iban'] ?? ''));
		$savings = isset($_POST['savings']) === true;

		$bank_account->name = $name;
		$bank_account->iban = $iban;
		$bank_account->savings = $savings === true ? 1 : 0;

		$errors = [];
		if ($name === '') {
			$errors['name'] = 'mandatory';
		}

		if ($savings === true && $iban === '') {
			$errors['iban'] = 'mandatory_for_savings';
		}

		if (count($errors) > 0) {
			return $errors;
		}

		$bank_account->save();

		Session::redirect('/finances/settings?action=bank_accounts');
	}

	/**
	 * Render the rule form template
	 *
	 * @access private
	 * @param \Finance_Rule $rule
	 * @param array $selected_fields
	 * @param array $errors
	 */
	private function render_rule_form(\Finance_Rule $rule, array $selected_fields, array $errors): void {
		$this->template = 'finances/settings/rule_form.twig';
		$template = Template::get();
		$template->assign('rule', $rule);
		$template->assign('selected_fields', $selected_fields);
		$template->assign('errors', $errors);
		$template->assign('categories', \Finance_Category::get_all_active());
		$template->assign('match_types', \Finance_Rule::get_match_types());
		$template->assign('fields', \Finance_Rule::get_fields());
	}

	/**
	 * Get the category from the request
	 *
	 * Returns null when the category does not exist, so the caller can
	 * redirect back to the list.
	 *
	 * @access private
	 * @return ?\Finance_Category $category
	 */
	private function get_category_from_request(): ?\Finance_Category {
		try {
			$category = \Finance_Category::get_by_id((int) ($_GET['category_id'] ?? 0));
		} catch (\Exception $e) {
			return null;
		}

		if ($category->id === null) {
			return null;
		}

		return $category;
	}

	/**
	 * Get the rule from the request
	 *
	 * Returns null when the rule does not exist, so the caller can
	 * redirect back to the list.
	 *
	 * @access private
	 * @return ?\Finance_Rule $rule
	 */
	private function get_rule_from_request(): ?\Finance_Rule {
		try {
			$rule = \Finance_Rule::get_by_id((int) ($_GET['rule_id'] ?? 0));
		} catch (\Exception $e) {
			return null;
		}

		if ($rule->id === null) {
			return null;
		}

		return $rule;
	}

	/**
	 * Get the bank account from the request
	 *
	 * Returns null when the bank account does not exist, so the caller can
	 * redirect back to the list.
	 *
	 * @access private
	 * @return ?\Bank_Account $bank_account
	 */
	private function get_bank_account_from_request(): ?\Bank_Account {
		try {
			$bank_account = \Bank_Account::get_by_id((int) ($_GET['bank_account_id'] ?? 0));
		} catch (\Exception $e) {
			return null;
		}

		if ($bank_account->id === null) {
			return null;
		}

		return $bank_account;
	}
}
