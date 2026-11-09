---
title: Finance Flow (Bank Import, Categorization and Statistics)
version: 1.3
date_created: 2026-11-06
last_updated: 2026-11-09
owner: Roan Buysse
tags: [process, design, schema, app, finances]
---

# Introduction

This specification defines the finance area of the tool: importing bank statement exports, the rule-based categorization engine, manual review of uncategorized transactions, internal-transfer detection, soft-delete archiving and the statistics dashboard. It is written for AI agents and human developers who extend or maintain the finance flow, so any change keeps the existing invariants (dedup, first-match rules, internal transfers, statistics correctness).

## 1. Purpose & Scope

**Purpose.** A single, self-contained reference for the behaviour, data model, interfaces and edge cases of the finances area (`/finances`).

**In scope:**

- The `bank_account`, `finance_category`, `finance_rule`, `finance_rule_field` and `bank_transaction` schema.
- CSV import (`Bank_Import`) with format auto-detection, normalization and dedup.
- Rule matching (`Bank_Categorizer`) and the incoming/internal flags.
- The four web modules: `Finances` (overview + import + handle), `Finances\Transactions`, `Finances\Settings`, `Finances\Stats`.
- The statistics component (`Bank_Stats`) and its chart dashboard.

**Out of scope:**

- Exact Online / Peppol integrations (separate concerns, financial-legal effects).
- Authentication and the login/password-reset flow (own spec).
- Budgets, reporting exports, multi-currency conversion (not implemented).

**Audience.** AI coding agents working in this repository, and the developer reviewing their changes.

**Assumptions.** Single-user tool; Belgian bank exports in EUR; MySQL available through the skeleton Database layer; sodium-standard conventions of `AGENTS.md` apply (PSR-12 deltas, snake_case, decimal(10,2), etc.).

## 2. Definitions

| Term | Definition |
|---|---|
| Bank transaction | One line of a bank statement, stored in `bank_transaction`. Amount is signed: positive = incoming, negative = outgoing. |
| Finance category | A budget category (`finance_category`) used to group spending/income. |
| Finance rule | A matching rule (`finance_rule`) that maps selected transaction text fields to one category. |
| Global rule position | `finance_rule.sort_order`: one shared, contiguous position (1..N) across **all** rules regardless of category; the first rule in the list wins conflicts. Set by drag & drop. |
| Rule field | A transaction field a rule inspects: `description`, `counterparty_name`, `counterparty_account`, `communication` (`finance_rule_field`). |
| Match type | How a rule pattern is compared: `exact`, `contains`, `fuzzy`, `regex`. |
| import_key | Unique key deduplicating transactions: account id + transaction number + value date + amount + first 191 chars of communication. |
| Incoming flag | `bank_transaction.incoming`: `1` incoming, `0` outgoing, `NULL` not reviewed. Set on import for uncategorized transactions; cleared when a category is assigned. |
| Savings account | A `bank_account` with `savings = 1`: no statement is ever imported into it (it is not an import target, switcher entry or filter option), but its IBAN participates in the internal-transfer check — any transaction connected to it (outgoing **or** incoming) counts as internal. Its IBAN is mandatory. |
| Internal transfer | A transaction whose counterparty IBAN/name matches another configured bank account. Stored with `internal = 1`, never categorized, excluded from statistics and the review queue, and **hidden from every transaction list by default** — the archive's Transfers filter is the only way to list them. |
| Handle page | `/finances?action=handle` — triage queue listing all uncategorized, non-internal transactions. |
| Re-index | `/finances?action=reindex` — re-apply current rules to flagged transactions and re-check internal transfers. |
| Soft delete | Archiving via `archived` timestamp (magic column); archived rows are hidden, restorable, never counted. |
| Sticky message | Session-based flash message (`Session::set_sticky`) reported after a redirect. |

## 3. Requirements, Constraints & Guidelines

- **REQ-001**: Every transaction must carry `bank_account_id`, `transaction_number`, `value_date` and a non-zero `amount` (`NOT NULL`, FKs where applicable); rows failing this are skipped at import, never partially stored.
- **REQ-002**: Re-importing an export must never create duplicates. Dedup relies on the `import_key` unique index and `Bank_Transaction::get_by_import_key()`; the communication component is truncated to 191 characters (MySQL index limit).
- **REQ-003**: Rules must be applied **strictly in one explicit global order**: every active rule occupies a single global position (`sort_order`, unique and contiguous 1..N across all rules). The categorizer walks the list top to bottom and stops at the **first** rule that matches; only that rule's category is applied, the rules below it are never evaluated. The order is set by drag & drop (SortableJS) in the rules list and saved immediately on drop; a rule lower in the order can never override a rule above it. New rules are appended at the end (they match last).
- **REQ-004**: Assigning a category to a transaction must clear the `incoming` flag (`NULL`); after that the amount sign governs direction for that transaction.
- **REQ-005**: Internal transfers must never be categorized, never appear in the review queue (`internal = 0` conditions), and never inflate statistics. `bank_transaction.internal` is `NOT NULL DEFAULT 0` (migration `20260904_000005`).
- **REQ-006**: Deleting a transaction must be a soft delete (`Bank_Transaction::archive()`); archived rows must be restorable and excluded from every statistics query, pager list and review queue.
- **REQ-007**: The import must be a two-step flow. `?action=preview` parses the file, stores parsed rows in `$_SESSION['finance_import_preview']` and tells the user which account the file will land in. `?action=import` consumes the session rows exactly once; the target is **authoritative by file content where the format exposes it**: `Bank_Import::detect_account()` runs again on the stored rows, and when it matches a configured account the import goes there — the account selected at the preview is the fallback for formats without a recognizable own account (Keytrade). A KBC export therefore can never land in the wrong account because of a wrong selection, and a Keytrade export never misidentifies itself through its counterparty IBAN column. Savings accounts are rejected targets either way.
- **REQ-008**: A transaction whose counterparty matches an **archived or as-yet-unconfigured** bank account must be recoverable: re-index rechecks unflagged transactions against the current active account list.
- **REQ-009**: Manual assignments must survive the **default** re-index: categorized transactions are skipped, flagged internal transfers are never unflagged, and only rows whose assignment actually changes are saved. An explicit include-categorized sweep (`Bank_Reindex::run(true)`) intentionally re-judges *every* transaction — manual category picks are then overwritten and rows that no longer match any rule re-enter the handle queue; internal flags are still never removed and this mode is server-side only (never wired to a UI button).
- **REQ-010**: Statistics must be scoped by optional `?int $bank_account_id` and `?int $year` parameters and must exclude archived transactions in every query.
- **REQ-011**: The transactions list must show a looking-glass detail link carrying `back=` with the current URI so the detail page restores filters, sorting and page; unknown ids redirect to the list.
- **REQ-012**: Lists must use `\Skeleton\Pager\Web\Pager` with explicit `add_sort_permission()` per sortable column; filter forms are plain GET forms and must not carry the pager's `q`/`p` params. **Exception**: the auto-link rules list (`?action=rules`) is a fixed, fully rendered flat list because drag & drop reordering must span the complete rule set; it renders `Finance_Rule::get_all_active()` directly (rules are reasonable in number for a single-user tool) and has no pager or search.
- **REQ-013**: Savings accounts must exist purely as a matching anchor: `Bank_Account::get_all_active()` (used by `Bank_Import::import_rows`, `Bank_Reindex` and `get_by_iban`) includes them so their IBAN triggers `internal = 1` on matching transactions, while `get_all_uploadable()` (`savings = 0`, active) drives every list a user uploads or filters against: the overview switcher + import target, the transactions account filter, the stats account filter. Attempting to import into a savings account is rejected with the sticky error `savings`. The settings bank-account list shows all accounts with a savings badge, and a savings account requires its IBAN (validation error `mandatory_for_savings`).
- **REQ-014**: Internal transfers must be **hidden by default in every transaction list**, so a statement view never shows money circulating between own accounts. The overview pager (`Finances::get_transaction_pager()`) excludes them unconditionally with `internal = 0`; the archive (`Finances\Transactions`) excludes them unless its Transfers filter selects `all` (list them alongside everything) or `internal` (list nothing but them). Statistics (`Bank_Stats`, `get_stats()`, `get_all_stats()`) and the handle queue exclude them unconditionally and are never affected by the filter. An absent or unknown filter value falls back to the hidden default; the historical `exclude` value is accepted as a synonym of it. The archive card header names the active state ("Internal transfers hidden" / "Including internal transfers" / "Internal transfers only") so a hidden set is never mistaken for an empty one.
- **SEC-001**: Uploaded files are read from `$_FILES['file']['tmp_name']` only, validated with `UPLOAD_ERR_OK`; parse failures must be logged via `error_log` and returned as a sticky error, never shown raw.
- **SEC-002**: The `back=`/`return_to` redirect targets are validated to start with `/finances` (open-redirect protection); `return_to` must additionally contain `action=handle`.
- **SEC-003**: No credentials or file contents are echoed back or logged beyond an error log line.
- **CON-001**: All money columns are `DECIMAL(10,2)`; amounts are parsed from Belgian comma decimals (`str_replace(',', '.')`).
- **CON-002**: Timing columns follow the magic-column policy: `created NOT NULL`, `updated`/`archived` nullable, never set by hand.
- **CON-003**: Rule patterns and matching live in the database; rules are **global** (not per-account). Per-rule field links are preloaded in the `Bank_Categorizer` constructor because model lookups are lazy and would query per rule per transaction.
- **GUD-001**: Interfaces, classes and parameters follow the skeleton conventions in `AGENTS.md` (snake_case domain code, snake_case for method/properties in modules).
- **GUD-002**: Reusable queries aggregate in `lib/component/Bank/Stats.php` as static methods sharing a private `get_where()`/`get_params()` pair; templates stay thin.
- **PAT-001**: Module layout per `AGENTS.md`: overview module `Finances` (`/finances`) + settings module `Finances\Settings` (`/finances/settings`) + `Finances\Transactions` + `Finances\Stats`, each with its own template under `app/front/template/finances/`.

## 4. Interfaces & Data Contracts

### 4.1 Schema (migration `20260904_000001_finance.php` + `000002`–`000005`)

| Table | Key columns | Notes |
|---|---|---|
| `bank_account` | `name`, `iban`, `created`, `updated`, `archived` | `get_by_iban()` lookup; archived accounts are inactive (`get_all_active()`). |
| `finance_category` | `name`, `color`, timing columns | Soft deletable. |
| `finance_rule` | `finance_category_id` (FK), `name`, `match_type` (`exact`/`contains`/`fuzzy`/`regex`), `pattern` (TEXT), `sort_order` | Global position; see REQ-003. Migration `20261106_000001_rule_global_order.php` renumbered the historical per-category values into one global sequence grouped per category. |
| `finance_rule_field` | `finance_rule_id` (FK), `field` | Unique per (rule, field); a rule matches one or more fields. |
| `bank_transaction` | `bank_account_id` (FK), `finance_category_id` (FK nullable), `finance_rule_id` (FK nullable, audit: which rule assigned the category — see `spec-design-categorization-flow.md`), `transaction_number`, `value_date`, `transaction_date`, `description`, `counterparty_name`, `counterparty_account`, `amount DECIMAL(10,2)` (signed), `currency`, `balance`, `communication`, `incoming TINYINT NULL`, `internal` (NOT NULL DEFAULT 0), timing columns | Unique index `import_key (bank_account_id, transaction_number, value_date, amount, communication(191))`. |

### 4.2 `Bank_Import` (lib/component/Bank/Import.php)

| Method | Contract |
|---|---|
| `read_normalized_csv(path): string[]` | Strips UTF-8 BOM, converts ISO-8859-1 → UTF-8, normalizes CRLF/CR → LF, splits lines. Throws on unreadable file. |
| `parse_rows(lines): array` | Detects format from the first non-empty line: Keytrade when the header starts with `Uittreksel` and has ≥ 7 columns; otherwise KBC (semicolon, ≥ 18 columns, no header). Returns uniform rows: `account, transaction_number, value_date, transaction_date, description, counterparty_name, counterparty_account, amount, currency, balance, communication`. Rows without account/number/date or with amount `0.0` are dropped; empty strings become `null`. |
| `parse_kbc(lines)` | Column mapping: 0 account, 3 currency, 4 number, 5 value date, 6 description, 7 transaction date, 8 amount, 9 balance, 12 counterparty account, 13 bic, 14 counterparty name, 17 communication. Dates are `d/m/Y`. |
| `parse_keytrade(lines, header)` | Columns: 0 `Uittreksel` (number), 1 transaction date, 2 value date, 3 IBAN (**the counterparty's IBAN or `-` — never the exporting account**), 4 description (= communication too), 5 amount, 6 currency; no balance. Rows parse with `account = null`. Counterparty name + IBAN extracted from the description via `/\b(BE\d{2}[A-Z0-9]{11,})\b/`; text before the IBAN is the name. |
| `detect_account(rows): ?Bank_Account` | Matches the first non-empty row `account` against configured accounts by IBAN. Only meaningful for formats that carry the exporting account (KBC's `Rekeningnummer`); Keytrade files parse with `account = null` and always return `null` and fall back to the chosen account. **Authoritative at import**: `display_import` re-runs it on the preview rows and prefers the detected account over the posted one when it is non-null (see REQ-007). |
| `import_rows(rows, account): array` | Dedup via `get_by_import_key()` → skip; internal transfer → `internal = 1`, incoming flag from sign, counted in `RESULT_INTERNAL`; else categorize → set `finance_category_id` or set `incoming` from sign. Result array keys: `imported`, `skipped`, `uncategorized`, `transfers`. |
| `import(path, account): array` | Convenience: read + parse + import_rows. |

### 4.3 `Bank_Categorizer` (lib/component/Bank/Categorizer.php)

- Constructor loads `Finance_Rule::get_all_active()` — the rules in their **global position order** — and preloads, per rule id, its selected fields (`get_selected_fields()`) and category (`get_category()`).
- `categorize(Bank_Transaction): ?Finance_Category` — walks the ordered rule list and **returns on the first match**; later rules are never evaluated once one matches (`sort_order` order; `null` when nothing matches).

The full re-index sweep lives in `lib/component/Bank/Reindex.php` (`Bank_Reindex::run(bool $include_categorized = false): array`, returning `reindexed`/`transfers`/`uncategorized` counts — `reindexed` counts every *changed* assignment). `Finances::display_reindex()` delegates to it with the default (flagged-only) sweep; `run(true)` re-judges categorized transactions as well, overwriting manual picks and clearing the category of rows that no longer match (they re-enter the handle queue with the incoming flag from the amount sign). The same method powers server-side runs (CLI, no HTTP session) — keep it the single re-index implementation. Both sweeps handle internal-transfer re-checks first; internal flags are never removed.
- Rule value = selected transaction fields joined with a space; `null` when none holds a value (an empty field can never match).
- Match modes on normalized values (lowercase, deaccented chart map, non-alphanumerics stripped):
  - `exact`: whole normalized strings equal.
  - `contains`: normalized pattern is a substring.
  - `fuzzy`: normalized pattern of length ≥ 3, whole word or word-start match on space-normalized text (so `km` never hits e.g. `smithkman` and short patterns fail outright).
  - `regex`: raw value against a delimiter-included PCRE pattern; invalid patterns never match (`@preg_match`).

### 4.4 `Bank_Stats` (lib/component/Bank/Stats.php)

Static methods, all `(?int $bank_account_id = null, ?int $year = null)`, all excluding archived rows, sharing `get_where()`/`get_params()` (optional table alias):

- `get_totals()` — incoming/outgoing/net totals.
- `get_transfers()` — internal-transfer totals.
- `get_monthly()` — 12 zero-filled months when a year is selected, calendar grouping by `YEAR/MONTH(value_date)`.
- `get_category_totals()` — per category outgoing (JOIN `finance_category`), uncategorized grouped under a grey row (`UNCATEGORIZED_COLOR`), ordered by outgoing desc.
- `get_category_monthly(top_categories = 8)` — pivoted `periods` + `series`, top 8 categories by outgoing with the rest folded into "Other".
- `get_top_counterparties(limit = 10)` — top outgoing counterparties; the UI links each to `/finances/transactions?search=<name>`.

### 4.5 Web modules

| Module / URL | Actions | Behaviour summary |
|---|---|---|
| `Finances` `/finances` | `display` (default), `detail`, `handle`, `reindex`, `preview`, `import` | Overview: account + year `data-keep-params` selects (accounts = `get_all_uploadable()`, savings accounts absent), stats from `Bank_Transaction::get_stats()`, uncategorized count (internal excluded), searchable per-year transaction pager keyed to one account/year — the pager always adds `internal = 0`, internal transfers are never listed here. The import target and both preview/import steps reject savings accounts with the sticky error `savings`. The handle page's save accepts a per-row "create rule" checkbox that appends a fuzzy counterparty rule for the just-assigned category (the teach loop — see `spec-design-categorization-flow.md`). |
| `Finances\Transactions` `/finances/transactions` | `display`, `archive`, `restore`, `detail`, `export`, `import_categories`, `import_categories_confirm` | Cross-account list; filters: search, account (uploadable accounts only), year, date from/to, category, direction, amount min/max, transfers (`?internal`: `''` hide (default) / `all` include / `internal` only, `exclude` accepted as a synonym of the default; "More filters" collapse, auto-opened when one is active via `filter_advanced_count` — the hidden default does not count as active), status (`active`/`archived`/`all`, default `active`). Bounds apply to absolute amounts: absolutes when a direction filter is set. Card header states the transfer mode; the footer totals reuse the pager conditions, so they follow the same exclusion. Detail page archives/restores with confirm + sticky message, `back=` honoured after `str_starts_with('/finances')`, and shows the category source (rule with link / manual). `export` streams the filtered list as CSV; `import_categories` + `import_categories_confirm` apply offline category edits in a preview/confirm cycle (see `spec-design-categorization-flow.md`). |
| `Finances\Settings` `/finances/settings` | `display`, `rules`, `rule_order`, `rule_conflicts`, `category_new/edit/delete/merge`, `rule_new/edit/delete`, `bank_accounts`, `bank_account_new/edit/delete` | Category, rule (with field checkboxes) and bank account (IBAN + savings checkbox; savings requires the IBAN, error `mandatory_for_savings`) CRUD with validation errors passed back to the form templates. The bank-account list shows **all** active accounts with a savings badge. The rules list (`?action=rules`) is a **drag & drop sortable flat list** (SortableJS 1.15, asset `/sortablejs/Sortable.min.js`, loaded in the page's `javascript` block): a drop builds a hidden form with `order[]` = rule ids top-to-bottom and POSTs it to `?action=rule_order`, which validates each id (active rules only, unknown/archived skipped) and stores position 1..N per posted order. New rules get `sort_order = MAX(sort_order) + 1` across all rules (`Finance_Rule::get_next_sort_order()` has no category restriction). Rule/category cross-references in the list are preloaded in one pass to avoid lazy N+1 lookups, and the list shows each rule's audit match count. `category_merge` moves all transactions and active rules of a source category to a target and archives the source; `rule_conflicts` renders the read-only shadow/dead-rule report of `Bank_Rule_Analysis::analyze()` (both specified in `spec-design-categorization-flow.md`). |
| `Finances\Stats` `/finances/stats` | `display` | Read-only dashboard; account (empty = all) + year (`array_key_exists` check keeps "All years" reachable); data assigned as plain arrays, rendered with ApexCharts shipped inside the tabler npm package (`/tabler--core/dist/libs/apexcharts/...`); empty state card when no transactions. |

### 4.6 Session contracts

| Key | Shape | Producer | Consumer |
|---|---|---|---|
| `finance_import_preview` | `['bank_account_id' => int, 'rows' => parsed rows]` | `display_preview()` | `display_import()`; consumed and unset exactly once; missing/invalid → sticky `finance_import_result.error = expired`. |
| `finance_import_result` | `['imported','skipped','uncategorized','transfers']` or `['error' => 'upload_failed'\|'import_failed'\|'no_rows'\|'expired'\|'no_account']` | Import/preview actions | Overview banner. |
| `finance_handle_result` | `['saved']` or `['reindexed','transfers','uncategorized']` | `handle_save()` / `display_reindex()` | Handle page banner. |

## 5. Acceptance Criteria

- **AC-001**: Given an already imported KBC export, When the same file is imported again, Then the result reports `skipped = N` and no new `bank_transaction` rows exist.
- **AC-002**: Given a KBC file whose detected IBAN differs from the selected account, When the preview is shown, Then an informational notice names the detected account and states that confirming imports there; when the import is confirmed, Then the rows land in the **detected** account, not the posted one.
- **AC-024**: Given a Keytrade export whose rows include a transfer towards a configured account (counterparty IBAN in the file's IBAN column), When it is parsed and previewed with any other account selected, Then detection returns `null`, the preview warns that no match was found, and importing lands the file in the **selected** account — never in the counterparty's account (this was the 2026‑11‑06 wrong-import root cause: detection matched a counterparty IBAN and claimed the file belonged to KBC).
- **AC-025**: Given a transaction stored with `internal = 1`, When `/finances` lists the account/year, or `/finances/transactions` lists without an `internal` parameter (or with an unknown one, or the legacy `exclude`), Then the row is absent; When `?internal=all`, Then it lists next to the regular transactions; When `?internal=internal`, Then the list holds internal rows only. In all three states the statistics cards, `/finances/stats` charts and the handle queue are unchanged (they never show internal transfers), and the archive card header states the active transfer mode.
- **AC-003**: Given a preview is confirmed more than once, When the second `?action=import` runs, Then it reports `expired` and imports nothing (session rows consumed once).
- **AC-004**: Given a rule matching on `communication` with match type `fuzzy` and pattern `km`, When a transaction contains `kml`, Then no match occurs (pattern < 3 chars fails; whole-word/word-start only).
- **AC-005**: Given a Keytrade description `NAME BE68539007547034`, When parsed, Then `counterparty_name = "NAME"` and `counterparty_account = "BE68539007547034"`.
- **AC-006**: Given two configured accounts A and B, When a transaction exports money from A to B, Then it stores `internal = 1`, stays uncategorized, appears in neither the handle queue nor the statistics.
- **AC-007**: Given a transaction with an incoming flag `1`, When a category is assigned on the handle page, Then `finance_category_id` is set and `incoming` is cleared.
- **AC-008**: Given categorized transactions and ones flagged internal by hand, When Re-index runs, Then only rows whose assignment would actually change are saved and manual assignments survive.
- **AC-009**: Given accounts were configured after their transfer transactions were imported, When Re-index runs, Then matching unflagged rows get `internal = 1` and leave the review queue.
- **AC-010**: Given an archived transaction, When the Status filter `archived` is chosen, Then the row lists and can be restored from the RESTORE findings; with `active` (default) it does not list and its stats are excluded.
- **AC-011**: Given a detail URL with unknown `transaction_id`, When the detail action loads, Then it redirects to `/finances` (list) instead of erroring.
- **AC-012**: Given a list page with filters applied, When the looking-glass detail link is followed and Back clicked, Then the list is restored with the same filters, sorting and page (`back=` parameter).
- **AC-013**: Given amount filter `min = 100` with direction `out`, When applied to outgoing negatives, Then matching is mirrored on absolute values (>= 100 outgoing).
- **AC-014**: Given no transactions for a selection, When `/finances/stats` renders, Then an empty-state card is shown instead of charts.
- **AC-015**: Given a transaction with a match of no rule, When it is imported, Then it lands uncategorized in the handle queue with the flag (`incoming`) set from the amount sign.
- **AC-016**: Given two rules matching the same transaction whose categories differ, When the transaction is categorized (import or re-index), Then only the rule higher in the global order is evaluated and its category is assigned; the lower rule is never applied.
- **AC-017**: Given a drag & drop reorder in the rules list, When the drop posts `order[]`, Then every posted active rule gets `sort_order =` its list position (1..N) and matches are honored top-to-bottom from the next import or re-index onward; already categorized transactions are unchanged until a re-index touches only uncategorized ones (see AC-008).
- **AC-018**: Given a new rule is created, When it is saved, Then it receives the last global position (MAX + 1) so it can never preempt an existing rule at match time until it is dragged up.
- **AC-019**: Given the include-categorized sweep has been run once with rule set R and a transaction matches rule A in R, When a rule is inserted above A that also matches the transaction, Then a subsequent `Bank_Reindex::run(true)` reassigns that transaction to the new rule's category (or re-queues it when the new rule set matches nothing), while the default `run()` leaves it alone.
- **AC-020**: Given a savings account with a known IBAN, When any transaction in another account contains that IBAN in its counterparty, description or communication, Then it is stored with `internal = 1` (also by a `Bank_Reindex::run()` sweep after the savings account was configured later), regardless of the transfer direction.
- **AC-021**: Given a savings account, When the account switcher, transactions filter, stats filter or import target list is rendered, Then the savings account is absent; it is present on the settings bank-account list with a savings badge.
- **AC-022**: Given a row posted against a savings account id, When `?action=preview` or `?action=import` processes it, Then the import is rejected with the sticky error `savings` and no row is written.
- **AC-023**: Given a savings account saved without an IBAN, When the form posts, Then validation fails with `mandatory_for_savings` and the form repopulates.

## 6. Test Automation Strategy

- **Test Levels**: Unit for parsers and normalizers (`parse_rows`, `read_normalized_csv`, `parse_amount`, `extract_keytrade_counterparty`, regex and other match types), integration for the rules engine and import loop, end-to-end for the module flows through `util/bin/skeleton test:run`.
- **Frameworks**: skeleton-test (`Some_Thing_Test` classes under the configured test path). one test class per task; run scoped (`util/bin/skeleton test:run`) per `AGENTS.md`.
- **Test Data Management**: Build rule/category fixtures in the migrations directly (see `migration/20260904_000001_finance.php`); use purposely crafted transaction rows rather than real bank data (no customer details committed).
- **CI/CD Integration**: Not currently automated; run the scoped tests plus `vendor/bin/phpinsights analyse` on touched paths before committing.
- **Coverage Requirements**: The import dedup loop and the categorizer match modes are the highest-risk paths; aim for a matching test per accepted edge case in §9.
- **Performance Testing**: Not required (single-user tool); keep one `Bank_Categorizer` per import to avoid N rules × N transactions lazy DB lookups.

## 7. Rationale & Context

- **Two-step import** exists because a KBC export opened for the wrong account is a realistic mistake; the preview + explicit confirm (with account-mismatch warning) makes mis-categorization hard and re-import cheap.
- **Dedup on a composite import_key** rather than a bank-native transaction id: KBC and Keytrade both expose a statement/extract number, but only the combination of account + number + dates + amount + communication is stable across repeated exports; the 191-char truncation is an accepted edge case arising from MySQL's index limit (`communication` TEXT) and benign for statement parsing.
- **First-match-wins in one global order** is the conflict resolution mechanism: exactly one rule "owns" a transaction it matches, so two rules from different categories can never both claim it. The order is explicit and user-controlled (drag & drop) because the previous per-category `sort_order` left cross-category ties to the rule id; new rules start last so they cannot silently steal matches from established rules.
- **Internal transfers** must be excluded from category statistics, otherwise "household budgets" double-count money circulating inside the user's accounts. The check is performed at import **and** re-checked at re-index, because accounts are often configured after the first import.
- **Incoming flag vs amount sign**: uncategorized transactions need explicit user validation of direction because some statement lines (fees, corrections) mislead; once categorized, the sign is authoritative.
- **Soft delete** keeps statistics reproducible and lets the user undo a wrong archive; hard deletes happen only in tooling scripts (e.g. `util/bin/cleanup_kbc.php`).

## 8. Dependencies & External Integrations

### External Systems
- **EXT-001**: Belgian banks (KBC, Keytrade) — users export CSV statements that this tool imports; no API, no network.

### Third-Party Services
- **SVC-001**: Tabler npm package with bundled ApexCharts — chart rendering on `/finances/stats`; loaded as static assets, no runtime API key.

### Infrastructure Dependencies
- **INF-001**: MySQL/MariaDB via the skeleton `Database` layer and migrations (`util/bin/skeleton migrate:up` — run by a human).
- **INF-002**: PHP session storage — import preview rows (largest payload) are held in `$_SESSION['finance_import_preview']` between the two import steps.

### Data Dependencies
- **DAT-001**: `bank_transaction` import_key — the dedup contract; any change must keep the unique index definition aligned with `get_by_import_key()`.

### Technology Platform Dependencies
- **PLT-001**: PHP with `mbstring`/`iconv` (BOM, ISO-8859-1 decoding), `str_getcsv` and PCRE `preg_*` for regex rules.

### Compliance Dependencies
- **COM-001**: Monetary data is `DECIMAL(10,2)` — never `FLOAT` — so Exact Online / Peppol booking amounts derived later from these tables stay exact.

## 9. Examples & Edge Cases

```php
// Edge case: KBC row with a trailing unknown column count drops out silently
// (parse_kbc requires >= 18 columns).

// Edge case: an identical pair differing only past char 191 of communication
// would collide on import_key (accepted limitation).
$communication = str_repeat('X', 200);           // two 200-char descriptions
//               str_repeat('X', 199) . 'Y'      // both truncate to 191 'X'

// Edge case: fuzzy match normalized text "V.V.S. vzw" vs pattern "vvs vzw"
//   exact-contains would match the substring — fuzzy requires the whole word:
//   normalize('V.V.S. vzw')  -> 'vvsvzw'
//   normalize('vvs vzw')     -> 'vvsvzw'      (exact == contains success)
//   word match needs ' vvs ' — 'mineralwater v.v.s. vzw' becomes
//   'mineralwater vvsvzw' and 'vvs' ALONE would not match the middle.

// Edge case: a transaction identical in every key field is resumed on
// re-import: get_by_import_key returns the existing row -> skipped++.

// Edge case: a transaction saved into account A whose counterparty
// already matches account B's IBAN — flagged internal at import:
if ($transaction->is_internal_transfer($bank_accounts) === true) {
	$transaction->internal = 1;
	// categorized statistics exclude internal transfers
}

// Edge case: legacy unflagged rows from before account B existed —
// fixed by the re-index (display_reindex) sweeping internal=0 rows.
```

## 10. Validation Criteria

- Every schema change to this area lives in a new migration; tables keep `uuid`, `created` `NOT NULL`, nullable `updated`/`archived`, `DECIMAL(10,2)` for money, and FKs in canonical order (see `CON-001/002`, AGENTS.md).
- A fresh export re-imported against existing data produces zero duplicates (AC-001).
- Rule matching always honours the single global top-to-bottom order: no rule lower in the list can change the category assigned by a rule above, and rule positions stay unique and contiguous after any reorder, delete or create (AC-016, AC-017, AC-018).
- The re-index modes behave as documented: the UI button and the default `run()` never touch categorized transactions, and every change to a transaction's category or flags is the result of exactly one assigned rule in global position — verified by a count of changed assignments when the include-categorized sweep runs (see AC-019).
- Internal transfers never appear in the handle queue, the statistics or **any transaction list** after any of: import, re-index, archive, restore, account re-configuration (AC-006, AC-009, AC-025). The only way to list them is the archive's Transfers filter.
- All lists construct a `Pager` with explicit sort permissions and a GET filter form that does not carry `q`/`p` (REQ-012).
- All detail/archive/restore/handle flows redirect with sticky result messages validated against `str_starts_with('/finances')` (REQ-011, SEC-002).
- Scoped tests for `Bank_Import`, `Bank_Categorizer` and `Bank_Stats` pass, and `vendor/bin/phpinsights analyse` is clean on touched paths.

## 11. Related Specifications / Further Reading

- `spec-design-categorization-flow.md` — categorization workflow improvements built on top of this spec: rule audit column, category merge, handle-page teach loop, rule conflict report and the CSV export/import round trip.
- `AGENTS.md` — repository conventions, coding standard, permissions (ask before migrations, `composer` changes, writes to Exact Online/Peppol).
- `migration/20260904_000001_finance.php` (+ `000002`–`000005`), `migration/20261106_000001_rule_global_order.php`, `migration/20261106_000002_savings_bank_account.php` and `migration/20261109_000001_transaction_rule_audit.php` — authoritative schema source.
- Future spec: landing-page architecture (per-area cards on the overview); login/password-reset flow.
