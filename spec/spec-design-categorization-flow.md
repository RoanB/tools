---
title: Categorization Flow Improvements (Category Merge, Rule Audit, Teach Loop, Conflict Report, CSV Round Trip)
version: 1.0
date_created: 2026-11-09
last_updated: 2026-11-09
owner: Roan Buysse
tags: [design, schema, tool, app, finances]
---

# Introduction

This specification extends the finance flow of `spec-process-finance-flow.md` with five related improvements to the categorization workflow. Motivation (measured on the live data of 2026-11-09): of 2,269 stored transactions only 39 are uncategorized, but 408 (20%) match **more than one** rule — the first-match-wins order silently decides, and the surviving category is frequently not the one the user intends. The rule set (333 rules, 326 of type `contains`) grew without feedback about which rules actually fire.

The improvements close four gaps: no record of *which* rule categorized a transaction (audit), no way to teach a rule from the triage queue (teach loop), no report about overlapping rules (conflict report), and no offline review path (CSV round trip). On top sits a category-merge action to consolidate overlapping categories.

## 1. Purpose & Scope

**Purpose.** A single reference for the categorization-improvement features: schema, contracts, behaviour and acceptance criteria, complementary to `spec-process-finance-flow.md` (which stays authoritative for import, matching, internal transfers and statistics).

**In scope:**

- `bank_transaction.finance_rule_id` audit column and its population.
- Category merge (`Finance_Category::merge_into()`, settings UI).
- The teach loop on the handle page ("also create rule from this pick").
- The rule conflict report (`Bank_Rule_Analysis`, settings action).
- The CSV export / re-import round trip of the transactions archive.

**Out of scope:**

- Everything already specified in `spec-process-finance-flow.md` (import formats, dedup, matching modes, internal transfers, statistics).
- Automatic categorization suggestions from history (future work).
- Any external service: all features run locally against the project database.

**Audience.** AI coding agents and the developer reviewing their changes.

**Assumptions.** Single-user tool; the skeleton conventions of `AGENTS.md` apply; migrations are run by a human (`util/bin/skeleton migrate:up`).

## 2. Definitions

| Term | Definition |
|---|---|
| Audit link | `bank_transaction.finance_rule_id`: the rule that assigned the current category, `NULL` for manual or uncategorized rows. |
| Manual assignment | A category picked by a human (handle page, detail page, CSV import). Stores `finance_rule_id = NULL`. |
| Category merge | Moving every transaction and every rule of a source category to a target category, then archiving the source. |
| Teach loop | Creating a rule from the handle page at the moment a category is assigned, so future transactions of the same merchant auto-categorize. |
| Shadow pair | Two rules of (possibly different) categories matching the same transaction; the upper rule wins, the lower is *shadowed*. |
| Dead rule | An active rule that is the first match for zero stored transactions. |
| Conflict report | The simulated sweep of all active rules over all active non-internal transactions, reporting dead rules and shadow pairs. |
| CSV round trip | Export of the (filtered) transaction list to CSV, offline edit of the category column, re-import that applies only category changes after a preview. |

## 3. Requirements, Constraints & Guidelines

- **REQ-101**: Every rule-driven categorization (import and both re-index modes) must store the winning rule in `bank_transaction.finance_rule_id`. Every manual assignment (handle save, detail save, CSV import) must store `NULL` in that column. Removing a category (uncategorizing) must also clear it.
- **REQ-102**: The audit column must never change the behaviour of matching, statistics or the review queue; it is provenance metadata only. Internal transfers keep both `finance_category_id` and `finance_rule_id` `NULL`.
- **REQ-103**: A category merge must move **all** transactions (including archived rows) and **all** active rules of the source category to the target, then archive the source. It must run inside a database transaction so it is all-or-nothing. A category cannot be merged into itself. The merged-into category keeps its own name and color.
- **REQ-104**: The teach loop creates at most one rule per handle-save per transaction, only when a category was assigned in the same submission, only when the pattern source (counterparty name, falling back to the description) is non-empty, and never when an active rule with the same pattern, match type and category already exists. The new rule is appended at the end of the global order (`MAX(sort_order) + 1`), matching the append convention of `spec-process-finance-flow.md` REQ-003.
- **REQ-105**: The teach-loop rule matches with type `fuzzy` on the counterparty-name field (falling back to the description field when no counterparty is stored). `fuzzy` is chosen over `contains` because it needs whole-word or word-start matches and is the safest non-exact mode for merchant names.
- **REQ-106**: The conflict report must be a read-only simulation: it never writes. It reports, over all active non-internal transactions: the number of transactions matching more than one rule, per-rule first-match (win) counts with the dead rules (zero wins), and the shadow pairs (winning category → shadowed category, count ≥ 1) sorted by count descending. The sweep must reuse the categorizer's matching logic (one instance, preloaded lookups) rather than re-implementing it.
- **REQ-107**: The CSV export must stream the transactions of the **current filter state** (the same conditions the list shows), columns: `id`, `value_date`, `counterparty_name`, `description`, `communication`, `amount`, `account`, `category`. Amount is the signed decimal with a dot; category is the name or empty for uncategorized. Internal transfers are included only when the Transfers filter asks for them (the filter state decides, like the list).
- **REQ-108**: The CSV re-import applies **category changes only**, matched by transaction `id`, with the category identified by **exact name** (after trimming) among active categories. An empty category cell means "no change". Unknown ids and unknown category names are skipped and reported. The flow is two-step with a preview (parsed diff held in `$_SESSION`, consumed exactly once) mirroring the bank-import preview contract; the apply step changes nothing but `finance_category_id` (+ `incoming`, `finance_rule_id` per REQ-101) on matching rows. Rows whose category does not actually change are not saved.
- **REQ-109**: The rules list shows, per active rule, how many stored non-archived transactions carry its audit link, so dead and greedy rules are visible without running the report.
- **REQ-110**: The transaction detail page shows how the category was assigned: the linked rule (with a link to its edit form) for rule-driven rows, "manual" for rows with `finance_rule_id IS NULL` and a category, nothing for uncategorized rows. Rows categorized before the audit column existed are stamped by the kept utility `util/bin/backfill_rule_audit.php` (dry-run default, `--execute`): it simulates the current rules and writes the link **only** where the winning rule's category equals the stored category, changing no categories — rows whose stored assignment the current rule set no longer reproduces (manual or drifted) keep their NULL link.
- **SEC-101**: The CSV upload is validated like the bank import (`$_FILES`, `UPLOAD_ERR_OK`, parse errors logged via `error_log` and returned as a sticky error, never shown raw).
- **SEC-102**: Sticky-result keys and redirect targets follow the existing module contracts (`finance_transactions_result` for the archive, `finance_handle_result` for the handle page, `finance_settings_result` for settings actions; `str_starts_with('/finances…')` redirect validation).
- **CON-101**: Schema change via one migration, narrow `ALTER` statements (column first, foreign key second, per `AGENTS.md`); `finance_rule_id INT NULL` with FK `bank_transaction_ibfk_3` referencing `finance_rule(id)`. No other table changes.
- **GUD-101**: All matching logic stays in `Bank_Categorizer`; the conflict report consumes `Bank_Categorizer::get_matching_rules()` and never re-implements a match mode.

## 4. Interfaces & Data Contracts

### 4.1 Schema addition (migration `20261109_000001_transaction_rule_audit.php`)

```
ALTER TABLE `bank_transaction`
  ADD COLUMN `finance_rule_id` INT NULL AFTER `finance_category_id`;
ALTER TABLE `bank_transaction`
  ADD CONSTRAINT `bank_transaction_ibfk_3`
  FOREIGN KEY (`finance_rule_id`) REFERENCES `finance_rule` (`id`);
```

### 4.2 `Bank_Categorizer` (lib/component/Bank/Categorizer.php) — additions

| Method | Contract |
|---|---|
| `get_matching_rules(Bank_Transaction): array` | All active rules matching the transaction, in global sort order (the full walk, no early return). |
| `categorize_with_rule(Bank_Transaction): array` | `[ ?Finance_Category $category, ?Finance_Rule $rule ]` — the first match's category and rule; `[null, null]` when nothing matches. |
| `categorize(Bank_Transaction): ?Finance_Category` | Unchanged signature; now delegates to `categorize_with_rule()` and returns the category. |

### 4.3 `Finance_Category::merge_into(Finance_Category $target): array`

Moves `bank_transaction.finance_category_id` (all rows, archived included) and `finance_rule.finance_category_id` (active rules) from `$this` to `$target`, archives `$this`, and returns `['transactions' => int, 'rules' => int]`. Throws when `$target->id` equals `$this->id`. Wrapped in a database transaction (`START TRANSACTION` / `COMMIT` / `ROLLBACK` via the skeleton `Database` layer).

### 4.4 `Finance_Rule` — additions

| Member | Contract |
|---|---|
| `get_next_sort_order(): int` | `MAX(sort_order) + 1` over active rules (moved from the `Settings` module, which now calls this). |
| `find_duplicate(string $pattern, string $match_type, int $category_id): ?Finance_Rule` | The active rule with the same pattern, match type and category, or `null`. Used by the teach loop (REQ-104). |

### 4.5 `Bank_Rule_Analysis` (new, lib/component/Bank/Rule_Analysis.php)

`analyze(): array` — static, read-only. Builds one `Bank_Categorizer`, loads all active non-internal transactions, and returns:

```
[
  'transactions'     => int,   // rows evaluated
  'multi_matched'    => int,   // rows matching >= 2 rules
  'wins'             => [rule_id => count],      // first-match counts
  'dead_rule_ids'    => [rule_id, ...],          // active rules with 0 wins
  'shadow_pairs'     => [ 'winner name -> shadowed name' => count ],  // arsort'ed
]
```

### 4.6 Web actions

| Module / URL | Action | Behaviour |
|---|---|---|
| `Finances\Settings` | `category_merge` | GET shows the merge form (target-category select, counts of what will move); POST `merge_save` runs `merge_into()` and redirects with a sticky summary. Rejects `target_id == source_id` (sticky error `same`). |
| `Finances\Settings` | `rule_conflicts` | Renders the conflict report (template `rule_conflicts.twig`); read-only. |
| `Finances\Settings` | `rules` | Rules list gains a "Matched" column (audit counts, one aggregate query, REQ-109) and a link to the conflict report. |
| `Finances` | `handle` | Each row gains a "Also create rule" checkbox (`transactions[<id>][create_rule]`). `handle_save()` creates the teach-loop rule per REQ-104/105 and reports `rules_created` in the sticky result. |
| `Finances\Transactions` | `export` | Streams `transactions-<date>.csv` (attachment) with the current filter conditions (REQ-107). |
| `Finances\Transactions` | `import_categories` | GET shows the upload form; POST `import_preview` parses the CSV and stores the diff in `$_SESSION['finance_category_import']`, then renders the preview (apply/skip counts + rows). |
| `Finances\Transactions` | `import_categories_confirm` | Consumes the session diff exactly once, applies category changes (REQ-108), redirects with a sticky summary. Missing/expired session → sticky error `expired`. |
| `Finances\Transactions` | `detail` | Shows the category provenance row (REQ-110); a manual save clears `finance_rule_id`. |

### 4.7 Session contracts (additions)

| Key | Shape | Producer | Consumer |
|---|---|---|---|
| `finance_category_import` | `[ 'changes' => [transaction_id => ['from' => name, 'to' => name]], 'skipped_ids' => int, 'skipped_names' => [name, ...] ]` | `import_categories` (preview) | `import_categories_confirm`; consumed and unset exactly once; missing → sticky `finance_transactions_result.error = expired`. |
| `finance_settings_result` | `[ 'merged_transactions' => int, 'merged_rules' => int ]` or `[ 'error' => 'same' ]` | Category merge | Settings category list banner. |
| `finance_handle_result` | gains `'rules_created' => int` next to `'saved'` | `handle_save()` | Handle page banner. |

## 5. Acceptance Criteria

- **AC-101**: Given a transaction categorized at import by rule R, When its detail page opens, Then "Categorized by" names R with a link to the rule form, and the rules list shows R with a matched count ≥ 1.
- **AC-102**: Given a category assigned by hand on the handle or detail page, When the row is inspected, Then `finance_rule_id` is `NULL` and the detail page reports a manual assignment.
- **AC-103**: Given the source category S with transactions (active and archived) and active rules, When S is merged into T, Then every transaction row of S now carries T, every active rule of S points at T keeping its global position, S is archived, the whole operation is atomic, and the counts are reported in the sticky message.
- **AC-104**: Given the merge form of category S, When the target select posts S itself, Then the merge is rejected with the sticky error `same` and S stays active.
- **AC-105**: Given a handle submission that assigns category C to a transaction with counterparty "ACME" and the rule checkbox ticked, When the save completes, Then a new active rule exists (fuzzy, pattern "ACME", field counterparty_name, category C, last global position) and the banner reports it; future transactions naming ACME categorize automatically.
- **AC-106**: Given an active rule with the same pattern, match type and category already exists, When a teach-loop save would create an identical rule, Then no second rule is created.
- **AC-107**: Given a transaction with an empty counterparty and description, When the teach-loop checkbox is ticked, Then no rule is created for it (pattern source empty).
- **AC-108**: Given the conflict report action, When it renders, Then it lists only, per REQ-106: multi-match count, per-rule win counts with dead rules flagged, and shadow pairs sorted by count — and it writes nothing.
- **AC-109**: Given a filtered archive view, When "Export CSV" is clicked, Then the download holds exactly the filtered rows with the columns of REQ-107, amounts as signed dot decimals and uncategorized rows with an empty category.
- **AC-110**: Given an edited CSV whose category names all resolve, When the preview is confirmed, Then exactly the rows whose category changed are updated (manual, `finance_rule_id` cleared, `incoming` cleared when a category was set), the preview is consumed once (second confirm reports `expired`), and the banner reports applied/skipped counts.
- **AC-111**: Given a CSV with an unknown id or an unresolvable category name, When the preview renders, Then those rows are listed as skipped and the confirm applies nothing to them.
- **AC-112**: Given a re-index (both modes) after the migration, When a transaction is re-categorized by rule R, Then its audit link is R; when it becomes uncategorized, the audit link is cleared; internal transfers never gain a category or a rule link.

## 6. Test Automation Strategy

The project has no test path configured yet, so this change set is verified with `vendor/bin/phpinsights analyse` on every touched path plus a read-only dry-run script against the live database (merge simulation and CSV round trip executed on a copy first). Once a test path exists, unit tests for `categorize_with_rule`, `find_duplicate` and `merge_into` are the first to write (highest logic density, no HTTP involved).

## 7. Rationale & Context

- **Audit column before anything else**: every other feature (matched counts, provenance display, conflict report cross-checking) reads it, and it is the only part requiring a migration.
- **Fuzzy for teach-loop rules**: merchant names arrive in many spellings ("ACME BV", "Acme bvba"); `fuzzy` matches whole words accent-insensitively and is strict enough (≥ 3 chars) to avoid the short-pattern trap that motivated `AC-004` in the base spec.
- **Merge instead of delete+reassign**: a plain category archive orphans its transactions (they keep pointing at an archived category) and its rules (they archive too); the merge keeps the user's history intact while consolidating, and the atomic transaction makes it safe to retry.
- **CSV round trip instead of an in-UI editor**: offline spreadsheets are the user's request ("a list I can download to match against") and avoid building a batch-edit UI; the preview/confirm contract is copied from the bank import so the interaction pattern stays familiar.
- **No external service**: everything runs on the project's own MySQL and PHP session storage (INF-001/002 of the base spec).

## 8. Dependencies & External Integrations

- **INF-101**: MySQL migration run by a human before deploying the code that writes the new column (the column is nullable, so deploying code first degrades gracefully: writes without the column would fail — run the migration first).
- Reuses: `Bank_Categorizer` (matching), `Skeleton\Database\Database` (transactions, aggregate queries), the sticky-session pattern, and the tabler templates of the finances area.

## 9. Examples & Edge Cases

```php
// Edge case: a rule is archived after it categorized transactions.
// The audit link keeps pointing at the archived rule: provenance is
// historical fact, the FK does not restrict it (soft delete only).

// Edge case: category merge with rules that keep their sort_order.
// Moving rules to the target category does NOT renumber the global
// order: the user set that order deliberately; only ownership moves.

// Edge case: teach-loop source selection.
// counterparty_name = 'ACME BV', description = 'BANCONTACT' ->
// pattern 'ACME BV' on field counterparty_name (description ignored).
// counterparty_name = NULL -> pattern from description instead.

// Edge case: CSV row that keeps its current category is a no-op:
// the apply loop compares old and new and never saves an unchanged row.

// Edge case: two categories with the same name cannot both be active in
// the resolve map: get_all_active() is keyed by name; the LAST mapping
// wins and the preview shows the resolved target name per row, so a
// duplicate name is visible before confirming.
```

## 10. Validation Criteria

- The migration adds the column and the FK as separate, narrow `ALTER`s (CON-101); `migrate:up` is run by a human.
- `vendor/bin/phpinsights analyse` is clean on: `lib/component/Bank/Categorizer.php`, `lib/component/Bank/Rule_Analysis.php`, `lib/component/Bank/Import.php`, `lib/component/Bank/Reindex.php`, `lib/model/Bank/Transaction.php`, `lib/model/Finance/Category.php`, `lib/model/Finance/Rule.php`, `app/front/module/Finances.php`, `app/front/module/Finances/Settings.php`, `app/front/module/Finances/Transactions.php`.
- After deployment: run one default re-index so future audit links accumulate; the conflict report then shows per-rule win counts.
- Category merges keep statistics consistent (totals unchanged, categories consolidated) because the merge only moves ownership.

## 11. Related Specifications / Further Reading

- `spec/spec-process-finance-flow.md` — the base finance flow this document extends (version 1.2).
- `AGENTS.md` — repository conventions and permissions (migrations need explicit human approval).
