<?php
/**
 * Bank_Categorizer
 *
 * Assigns a budget category to a bank transaction by applying the finance
 * rules in order; the first rule that matches wins. All rules are loaded
 * once per instance and reused for every transaction, so a large import
 * builds one categorizer and calls it per transaction instead of reloading
 * the rules from the database each time.
 *
 * Each rule selects one or more fields of the transaction to inspect and a
 * match type:
 *
 * - exact: the whole (normalized) field equals the pattern
 * - contains: the (normalized) field contains the pattern as a substring
 * - fuzzy: the (normalized) field contains the pattern, matching accents and
 *   case loosely
 * - regex: the raw field matches the pattern as a regular expression
 *
 * @author Roan Buysse <roan@buysse.be>
 */

class Bank_Categorizer {

	/**
	 * The active rules, loaded once in sort order
	 *
	 * @var array $rules
	 */
	private array $rules = [];

	/**
	 * The transaction fields each rule inspects, keyed by rule id
	 *
	 * Loaded once per instance: the model reads the field links from the
	 * database lazily, so uncached lookups would query the database for
	 * every rule on every transaction.
	 *
	 * @var array $rule_fields
	 */
	private array $rule_fields = [];

	/**
	 * The category of each rule, keyed by rule id
	 *
	 * @var array $rule_categories
	 */
	private array $rule_categories = [];

	/**
	 * Build a categorizer and load every active rule with its lookups
	 *
	 * @access public
	 */
	public function __construct() {
		$this->rules = \Finance_Rule::get_all_active();
		foreach ($this->rules as $rule) {
			$this->rule_fields[$rule->id] = $rule->get_selected_fields();
			$this->rule_categories[$rule->id] = $rule->get_category();
		}
	}

	/**
	 * Categorize a transaction and return its category
	 *
	 * Rules are applied in sort order; the first rule that matches decides.
	 * Returns null when no rule matches, leaving the transaction
	 * uncategorized for manual review.
	 *
	 * @access public
	 * @param \Bank_Transaction $transaction
	 * @return ?\Finance_Category $category
	 */
	public function categorize(\Bank_Transaction $transaction): ?\Finance_Category {
		[$category, $rule] = $this->categorize_with_rule($transaction);

		return $category;
	}

	/**
	 * Categorize a transaction and return its category with the winning rule
	 *
	 * Same first-match walk as categorize(), but the matched rule is
	 * returned alongside the category so callers can store the audit
	 * link. Both entries are null when no rule matches.
	 *
	 * @access public
	 * @param \Bank_Transaction $transaction
	 * @return array $result
	 */
	public function categorize_with_rule(\Bank_Transaction $transaction): array {
		$matching = $this->get_matching_rules($transaction);
		if (count($matching) === 0) {
			return [ null, null ];
		}

		$rule = $matching[0];

		return [ $this->rule_categories[$rule->id], $rule ];
	}

	/**
	 * Get every rule matching a transaction, in global sort order
	 *
	 * Unlike categorize() this walks the complete rule list instead of
	 * stopping at the first match. The conflict report uses it to find
	 * rules that match but never win, and it powers the first-match walk
	 * of categorize_with_rule().
	 *
	 * @access public
	 * @param \Bank_Transaction $transaction
	 * @return array $rules
	 */
	public function get_matching_rules(\Bank_Transaction $transaction): array {
		$matching = [];
		foreach ($this->rules as $rule) {
			if ($this->rule_matches($rule, $transaction) === true) {
				$matching[] = $rule;
			}
		}

		return $matching;
	}

	/**
	 * Check whether a single rule matches a transaction
	 *
	 * A rule with no selected field never matches. The transaction values
	 * for the selected fields are joined and matched against the pattern.
	 *
	 * @access private
	 * @param \Finance_Rule $rule
	 * @param \Bank_Transaction $transaction
	 * @return bool $matches
	 */
	private function rule_matches(\Finance_Rule $rule, \Bank_Transaction $transaction): bool {
		$value = $this->get_rule_value($rule, $transaction);
		if ($value === null) {
			return false;
		}

		switch ($rule->match_type) {
			case \Finance_Rule::MATCH_EXACT:
				return $this->match_exact($value, $rule->pattern);
			case \Finance_Rule::MATCH_CONTAINS:
				return $this->match_contains($value, $rule->pattern);
			case \Finance_Rule::MATCH_FUZZY:
				return $this->match_fuzzy($value, $rule->pattern);
			case \Finance_Rule::MATCH_REGEX:
				return $this->match_regex($value, $rule->pattern);
		}

		return false;
	}

	/**
	 * Build the text a rule matches against
	 *
	 * The values of the rule's selected fields are joined into a single
	 * string separated by a space. Returns null when none of the selected
	 * fields holds a value, so an empty field can never match.
	 *
	 * @access private
	 * @param \Finance_Rule $rule
	 * @param \Bank_Transaction $transaction
	 * @return ?string $value
	 */
	private function get_rule_value(\Finance_Rule $rule, \Bank_Transaction $transaction): ?string {
		$values = [];
		foreach ($this->rule_fields[$rule->id] as $field) {
			switch ($field) {
				case \Finance_Rule::FIELD_DESCRIPTION:
					$value = $transaction->description;
					break;
				case \Finance_Rule::FIELD_COUNTERPARTY_NAME:
					$value = $transaction->counterparty_name;
					break;
				case \Finance_Rule::FIELD_COUNTERPARTY_ACCOUNT:
					$value = $transaction->counterparty_account;
					break;
				case \Finance_Rule::FIELD_COMMUNICATION:
					$value = $transaction->communication;
					break;
				default:
					$value = null;
			}

			if (empty($value) === false) {
				$values[] = (string) $value;
			}
		}

		if (count($values) === 0) {
			return null;
		}

		return implode(' ', $values);
	}

	/**
	 * Exact match on the normalized value
	 *
	 * @access private
	 * @param string $value
	 * @param string $pattern
	 * @return bool $matches
	 */
	private function match_exact(string $value, string $pattern): bool {
		return self::normalize($value) === self::normalize($pattern);
	}

	/**
	 * Contains match on the normalized value
	 *
	 * @access private
	 * @param string $value
	 * @param string $pattern
	 * @return bool $matches
	 */
	private function match_contains(string $value, string $pattern): bool {
		$normalized_pattern = self::normalize($pattern);
		if ($normalized_pattern === '') {
			return false;
		}

		return strpos(self::normalize($value), $normalized_pattern) !== false;
	}

	/**
	 * Fuzzy match on the normalized value
	 *
	 * Same as contains, but the pattern is only required to appear as a
	 * whole word (or the start of a word) so short patterns such as "km" do
	 * not match the middle of a longer token.
	 *
	 * @access private
	 * @param string $value
	 * @param string $pattern
	 * @return bool $matches
	 */
	private function match_fuzzy(string $value, string $pattern): bool {
		$needle = self::normalize($pattern);
		if ($needle === '' || strlen($needle) < 3) {
			return false;
		}

		$text = preg_replace('/[^a-z0-9]+/', ' ', self::deaccent(mb_strtolower($value, 'UTF-8'))) ?? '';
		$escaped = preg_quote($needle, '/');

		$whole_word = '\b' . $escaped . '\b';
		$word_start = '\b' . $escaped . '[a-z]++';

		return preg_match('/' . $whole_word . '/', $text) === 1
			|| preg_match('/' . $word_start . '/', $text) === 1;
	}

	/**
	 * Regex match on the raw value
	 *
	 * The pattern is used as a full regular expression, delimiters included.
	 * Invalid patterns never match.
	 *
	 * @access private
	 * @param string $value
	 * @param string $pattern
	 * @return bool $matches
	 */
	private function match_regex(string $value, string $pattern): bool {
		if ($pattern === '') {
			return false;
		}

		return @preg_match($pattern, $value) === 1;
	}

	/**
	 * Normalize a value for comparison
	 *
	 * Lowercases, strips accents and removes every non-alphanumeric
	 * character, so "VVS VZW", "v.v.s." and "Vvs-Vzw" all compare equal.
	 *
	 * @access private
	 * @param string $value
	 * @return string $normalized
	 */
	private static function normalize(string $value): string {
		$value = self::deaccent(mb_strtolower($value, 'UTF-8'));

		return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
	}

	/**
	 * Remove diacritics from a UTF-8 string
	 *
	 * @access private
	 * @param string $value
	 * @return string $value
	 */
	private static function deaccent(string $value): string {
		$map = [
			'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
			'ç' => 'c',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
			'ý' => 'y', 'ÿ' => 'y',
			'ñ' => 'n',
		];

		return strtr($value, $map);
	}
}
