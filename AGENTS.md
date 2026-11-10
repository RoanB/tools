# AGENTS.md

Guidance for AI coding agents working in this repository. This complements
`README.md` (which is for humans) — it's the machine-readable onboarding: how to
set up, which commands to run, the conventions to follow, and what needs a human
in the loop.

Baseline coding standard: [tigron/skeleton-coding-standard](https://github.com/tigron/skeleton-coding-standard)
(built on [PSR-12](https://www.php-fig.org/psr/psr-12/) / PSR-1). This file
summarises the parts an agent needs and adds personal conventions; follow the
linked standard for exhaustive examples.

## Working style

- Be direct. Explain the underlying idea, not just the surface fix. Concrete
  examples over abstractions. Skip hedging filler.
- Prose over bullet points when it flows; lists only when structure helps.
- English for technical work.

## Specifications

Specification files live in `spec/` (naming: `spec-<purpose>-<slug>.md`, purpose
one of `schema`, `tool`, `data`, `infrastructure`, `process`, `architecture`,
`design`). They capture the requirements, data contracts, invariants and edge
cases of a feature for both humans and AI agents.

- **Check first**: before implementing anything, search `spec/` for a spec
  touching the affected area and follow it. In particular read
  `spec/spec-process-finance-flow.md` before any change to the finances area
  (import, categorization, handles, statistics).
- **New work**: when a request adds or changes a feature (new flow, schema, or
  integration), create a spec file for it in `spec/` in the same change. Keep
  specs focused on behaviour, contracts and acceptance criteria — not
  implementation walkthroughs.
- **Keep current**: update the spec's `last_updated` and content together when
  behaviour it describes changes, in the same PR.

## Technology stack

- **Language**: PHP (PSR-12 base), plus JavaScript/jQuery and Twig templates
- **Framework**: skeleton (`tigron/skeleton-*`, loosely coupled Composer packages)
- **Database**: MySQL (via PDO); skeleton object layer for models
- **Package manager**: Composer
- **Testing**: skeleton-test
- **Validation**: PHP Insights (enforces the coding standard)
- **Environment**: Manjaro Linux, VS Code over Remote SSH, Claude Code CLI

## Environment setup

```bash
composer create-project tigron/skeleton   # new project
composer update                           # install/update dependencies
# create config/environment.php with at least the database DSN (see Security)
util/bin/skeleton migrate:up              # apply database migrations
```

Point the web server's document root at `webroot/`; all requests route through
`webroot/handler.php`.

## Commands

Prefer file- or class-scoped commands for fast feedback. Run the full suite only
when explicitly asked.

```bash
# Scoped (preferred)
util/bin/skeleton test:run My_Test_Class          # one test class
util/bin/skeleton test:run My_Test_A,My_Test_B    # a list
util/bin/skeleton test:intense My_Test_Class      # run a class 10x
vendor/bin/phpinsights analyse lib/model/Some_Class.php   # lint one path

# Full suite (only when requested)
util/bin/skeleton test:all
vendor/bin/phpinsights                            # validate whole project
```

## Project structure

skeleton is intentionally minimal: it suggests structure without enforcing much.
"If you need it twice, make it a package."

```
project/
├── app/                  one subdirectory per application (matched by host/URI)
│   └── admin/            example app: config/, event/, module/, template/
├── config/               global config, loaded alphabetically
│   └── environment.php    DSN + secrets; loaded last; keep OUT of VCS
├── lib/                  application classes (autoloader paths below)
│   ├── base/  component/  model/
├── migration/            database migrations
├── util/                 CLI tooling (util/bin/skeleton ...)
└── webroot/handler.php   single front controller
```

Autoloader include paths are typically `lib/model/`, `lib/base/`, `lib/component/`.
The Skeleton autoloader maps underscores to a directory path, so the file
must live in a matching subdirectory: `Bank_Account` loads from
`lib/model/Bank/Account.php` and `Customer_Project` from
`lib/model/Customer/Project.php` (one class per file). A class and a
subdirectory can share a name (e.g. `Finance_Rule` in
`lib/model/Finance/Rule.php` alongside `lib/model/Finance/Rule/Field.php`),
matching the same class-plus-directory pattern used for modules.
Application events live in `\App\{APP_NAME}\Event\{Context}`, extending
`\Skeleton\Core\Application\Event\{Context}`. Start from `app/admin` as a reference.

## Application architecture

This project is based on `vvsjongeren` (one level above in the directory
tree). Use it as the reference for layout, module structure, and patterns —
this tool mirrors it closely.

This is a single tool hosted at `tools.buysse.io` with several independent
areas (finances, projects, etc.). Everything lives in `app/front`.

### Navigation structure

The **top menu** has a menu item for every area (finances, projects, etc.).
The main overview page (landing page after login) shows **cards for each
area**. Each card displays basic stats (counts, totals, recent activity) and
links into that area's module.

### Per-area modules

Each area module has two views:

- **Settings page** — full sidebar for configuration (category management,
  preferences, integrations, etc.). The sidebar is the primary navigation for
  this view.
- **Overview page** — tiny sidebar (icons only, collapsed by default) so the
  main content area gets maximum width for data tables, charts, and lists.

When building a new area module, start with the overview page (tiny sidebar)
and add a settings page only when configuration is needed. Keep the two views
in the same module directory (`module/` + `template/`).

## Code conventions

Full detail and examples: the [skeleton-coding-standard](https://github.com/tigron/skeleton-coding-standard).
The rules below are the ones agents most need, plus personal deltas.

### Highest priority

- **Never use inline control structures.** Always braces, body on its own line —
  PHP and JS alike. (Personal rule.)
- **Prefer longer, readable code over compact or clever code.** Meaningful names
  over shorthand; an explicit step over hidden nesting.
- **`===` / `!==` only**, never `==` / `!=` — the codebase targets strict typing.

### PHP

- Files start with `<?php`; no closing `?>`.
- Indent with **tabs**. 1TBS bracing (opening brace on the same line).
- Class member order: properties → public → protected → private → then the same
  order for static members.
- Naming: `snake_case` for properties, methods, and functions; `Class_Name` for
  classes; lowercase parameter types (`string`, `int`, `bool`).
- No useless `else` after `return`/`break`/`continue`; no empty `if`/`catch`; no
  function calls in `for` conditions (resolve once before the loop); no useless
  intermediate variable that's only returned.
- Arrays: short syntax `[]`; trailing comma on multi-line arrays only; single
  space around `=>`, no column alignment.
- Increment/decrement is its own statement, not folded into a call.
- Prefer single quotes. `use` imports sorted alphabetically. One class per file.
- Doc block on every class, property, and method; method body starts on the line
  directly after its doc block.

### JavaScript / jQuery

- Main JS file is `base.js`.
- `snake_case` for variables and functions (matches PHP, not JS convention —
  personal delta). Tabs, no inline control structures.

### Database

- Table names singular; every table has auto-increment `id`; foreign keys named
  `target_table_id`; link tables are `table_a_table_b`; parent/child is
  `table` / `table_item`.
- `uuid`, `created`, `updated`, `archived` are magic columns — skeleton manages
  them, don't set them by hand.
- Timing columns: `created` is `NOT NULL`; `updated` and `archived` are nullable
  with a default (`DATETIME DEFAULT NULL`). Rows created manually or imported
  don't supply them, so no timestamp is fabricated on partial writes. Mirror
  this in new tables and in `ALTER`s on existing ones.
- Currency is `decimal(10,2)` — never float (matters for Exact Online / Peppol
  amounts). Tables must have foreign keys for all linked objects. Column order:
  `id`, `uuid`, foreign keys, data, then timing columns (`created`, `updated`,
  `archived`).
- Keep `ALTER` statements narrow (don't add a column and a foreign key at once).
  Deprecated tables/columns get a leading underscore (`_customer`).

### API

- OpenAPI, via `skeleton-application-api`. **Casing exception**: API classnames,
  endpoints, and variables use **camelCase**, not `snake_case`. Keep domain code
  (`snake_case`) and the API surface (`camelCase`) straight.
- Only uniquely identifiable properties go in the path; respect object hierarchy
  (`/product/{productCategory}/{product}`). Exact lookup is `getByProperty`;
  partial search is `/search`. Page large lists with `?page=X`.

## Testing

- Framework: skeleton-test. Test classes are named `Some_Thing_Test` and live
  under the configured test path.
- Write a test for new behaviour. Run the scoped command for the class you're
  touching (see Commands) before the full suite.

## Security and secrets

- **Never commit credentials.** `config/environment.php` holds the database DSN
  and secrets and must stay out of version control (`.gitignore`).
- Exact Online API credentials (client ID/secret, OAuth tokens) and any Peppol
  access keys live in gitignored config, never in code or committed config.
- Never log secret values or pass them as command-line arguments.
- A minimal `environment.php`:

```php
<?php
return [
	'database' => 'mysqli://username:password@localhost/database',
];
```

## Good and bad patterns

- **Follow**: `app/admin` for application layout (module/event/template); skeleton
  object models in `lib/model/` for persistence patterns.
- **Avoid**: loose comparison (`==`), empty `catch` blocks, inline control
  structures, floats for money, hardcoded credentials or DSNs.
- **Follow**: `app/front/module/Finances.php` + `Finances/Settings.php` for the
  overview/settings split, `lib/component/Bank/Categorizer.php` for the
  rules-matching engine, and `lib/model/Bank/Transaction.php` for the
  dedup-on-import pattern.

## Feature patterns

### Password reset

Implement password reset the way `vvsjongeren` does it (this project mirrors it
exactly). The whole flow lives in the `Login` module using skeleton's
action-based dispatch: `/login?action=forgot_password` and
`/login?action=reset_password` map to `display_forgot_password()` and
`display_reset_password()` respectively. No custom routes are needed.

Flow, end to end:

1. `/login` shows a "Forgot password?" link to `/login?action=forgot_password`.
2. The user submits their email. `User::reset_password()` generates a 64-char
   hex token (`bin2hex(random_bytes(32))`), stores **only its SHA-256 hash** in
   `user_password_reset_token`, and emails a link with the raw token. Whether
   the address exists or not, the controller redirects with the same sticky
   message (`password_reset_email_sent`) — prevents user enumeration.
3. The email links to `/login?action=reset_password&token=...`. The controller
   validates the token via `Password_Reset_Token::get_by_raw_token()` (checks
   expiry, `used`, `archived`) before showing the new-password form.
4. The user sets a new password (min 8 chars, `Validation::password()`). On
   success the password is hashed and stored, the token is marked used, and the
   user is redirected to `/login` with `password_reset_success`.

Key files:

- `lib/model/Password/Reset/Token.php` — `Password_Reset_Token` model; must set
  `protected static $class_configuration = ['database_table' =>
  'user_password_reset_token']` because the table name differs from the class
  name. Token has a 24-hour expiry (`VALIDITY_SECONDS = 86400`), a `used` flag,
  and the raw value is never persisted.
- `lib/model/User.php` — `reset_password()`, `send_password_reset_email()`,
  `set_password()`, and static `reset_password_from_token()`.
- `lib/component/Mailer.php` — thin wrapper around `\Skeleton\Email\Email`
  (this project keeps it minimal; vvsjongeren also applies a BCC setting).
- `app/front/module/Login.php` — the two `display_*` handlers.
- `app/front/template/forgot_password.twig` and `reset_password.twig` — extend
  `_default/layout.login.twig`; the reset form carries the raw token in a
  hidden field.
- `store/email/template/password_reset/{subject,html,text}.twig` — the email,
  rendering `{{ reset_url }}` and `{{ user.firstname }}`. HTML extends
  `store/email/template/base.twig`.

Security notes: sender comes from the app-level `email_from` config in
`app/front/config/environment.php` (see `User::send_password_reset_email()`,
which reads `$config->email_from['from_email']` / `['from_name']`). The reset
URL is built from `$application->hostname`. Email templates live under
`store/email/`, which the Bootstrap email translator scans for translatable
strings. If the `user` table has no `verified` column, drop the
`$user->verified = true` line that vvsjongeren uses to complete verification
during a reset.

### List pattern (pagers)

Every list uses `\Skeleton\Pager\Web\Pager` (bootstrap5-tabler templates,
configured in `Bootstrap::boot()`: sticky pager, no jump-to). The model needs
the `\Skeleton\Pager\Page` trait. The module builds the pager, applies
conditions from GET params (search via `set_search()`, filters via
`add_condition()`), declares `add_sort_permission()` per sortable column,
then `page()`. Headers come from `$pager->create_header('Label', 'field')`
and are printed with `|raw`; sorting across a relation sorts on the joined
column (e.g. `finance_category.name`). The card footer prints
`pager.item_count`, `pager.per_page_links|raw` and `pager.links|raw`. Filter
forms are plain GET forms (they must not carry the pager's `q`/`p` params);
switcher selects (account, year) carry `data-keep-params` and are redirected
from `base.js` with the other query params preserved.

### Looking glass (detail link on lists)

Every pager list that shows entities with enough detail to warrant a closer
look gets a looking-glass icon as the last column. The icon is a small
`btn-outline-secondary` button with `ti ti-eye`, linking to a detail action
(e.g. `/finances?action=detail&transaction_id={{ item.id }}`). The detail
page lives in the same module as the list, loaded via a `display_detail()`
method that reads the entity ID from `$_GET`, loads it with
`Entity::get_by_id()`, and assigns it to a dedicated `detail.twig` template.
The detail template shows all fields in a card layout with a "Back" link to
the list.

When adding a new entity list, decide whether a read-only detail page adds
value over the existing edit form. Transaction lists almost always need one
(because the list truncates data); settings lists with Edit buttons usually
do not.

### Select2

`base.js` turns every `select.form-select` into a searchable select2
(bootstrap-5 theme, `minimumResultsForSearch: 5` so short lists stay clean).
Assets (select2 + apalfrey theme) are loaded in `_default/layout.base.twig`.
Never rely on inline `onchange` handlers for selects — select2 wraps the
element; use `data-keep-params` or a jQuery-bound handler in `base.js`
instead.

### Transactions archive

`app/front/module/Finances/Transactions.php` (`/finances/transactions`) lists
every imported transaction across all accounts with the extensive filter set
(account, year, date from/to, category, direction, amount min/max, free-text
search). The form shows the common filters only (search, account, category,
direction, status); the rarely used ones (year, date range, amount range,
internal transfers) sit in a Bootstrap collapse ("More filters") that opens
automatically when one of them is active — `filter_advanced_count` in the
module — so deep links like `?internal=internal` still show their filter in
use. All fields always submit, collapsed or not. The Transfers select is
`?internal`: empty (default) hides internal transfers, `all` lists them next to
the regular rows and `internal` lists nothing but them; the legacy `exclude`
value is a synonym of the default. The card header states which of the three is
active, so a hidden set never reads as an empty one, and the footer totals
follow the pager conditions and therefore the same exclusion. Without a
direction filter the amount bounds apply to the signed amount (outgoing is
negative); with `direction=out`/`in` they are mirrored to absolute values so
"min 100" always means "a transaction of at least 100 euro". The page is linked
from the finances sidebar (full and compact variants) and defaults to showing
every (non-internal) transaction of every account, unlike the overview which is
scoped to one account and year and never lists internal transfers at all
(`REQ-014` in `spec/spec-process-finance-flow.md`).

The list carries a looking-glass column linking to
`?action=detail&transaction_id=X` (`Finances\Transactions::display_detail()`,
template `finances/detail.twig`). The link carries `back=` with the list's
current URI, so the detail page's Back button restores filters, sorting and
page. `get_by_id()` throws on unknown ids, so the detail action catches and
redirects to the list.

Transactions are soft-deleted (`Bank_Transaction::archive()` / `restore()`):
every row carries an archive button and the detail page archives or restores
the transaction, both with a confirm dialog and a sticky success message.
The Status filter (`active` default / `archived` / `all`) makes archived rows
listable again so they can be reviewed and restored; archived items are also
viewable on the detail page.

### Finances module

The finances area (`/finances`) imports bank statements (CSV) into
`bank_transaction` rows, lets the user manage categories and matching rules,
and auto-categorises each transaction on import.

Layout mirrors the module conventions: the overview module `Finances`
(`/finances`) holds the import + stats, a settings module `Finances\Settings`
(`/finances/settings`, class `Settings`) holds categories, rules, and bank
accounts. Both live under `app/front/module/Finances/` with templates under
`app/front/template/finances/`.

Key pieces:

- `migration/20260904_000001_finance.php` — the schema. Link table is
  `finance_rule_field` (a `Finance_Rule` may match one or more fields via the
  `Rule_Type` / `Rule_Field` layer). Dedup across re-imports relies on a unique
  `import_key` per `bank_transaction` (bank account + transaction number +
  value date + amount + communication), the same de-duplication approach the
  `vvsjongeren` payment import uses.
- `lib/model/Bank/Account.php` — `Bank_Account` (`get_by_iban()`,
  `get_all_uploadable()` — the active non-savings accounts that user-facing
  lists show).
- `lib/model/Bank/Transaction.php` — `Bank_Transaction` (`get_by_import_key()` —
  compares the transaction number with leading zeros stripped so a bank's
  number-format change cannot defeat the dedup, `get_stats()`, `get_recent()`,
  `is_incoming()`).
- `lib/model/Finance/Category.php` — `Finance_Category` (`get_rules()`,
  `get_transaction_count()`).
- `lib/model/Finance/Rule.php` — `Finance_Rule`; the file and its sibling
  subdirectory `Finance/Rule/Field.php` (`Finance_Rule_Field`) share the name,
  exactly like modules (see "Project structure").
- `lib/component/Bank/Import.php` — `Bank_Import`: auto-detects format by
  header/column count (KBC: 18+ semicolon columns, no header; Keytrade: 7-column
  header), normalises BOM / `ISO-8859-1` / CRLF, parses rows, de-duplicates,
  and categorises. Keytrade counterparty name + IBAN are extracted from the
  description via an IBAN regex (`\bBE\d{2}[A-Z0-9]{11,}\b`); the Keytrade
  "IBAN" column is the **counterparty's** IBAN (or `-`), never the exporting
  account, so Keytrade rows parse with `account = null` and are never a
  detection candidate. The import is
  two-step: `parse_rows()` -> `detect_account()` (match the file's own account
  identifier against configured accounts by IBAN; only the KBC format carries
  one) and `import_rows()` (the
  shared dedup+categorise+store loop, used both directly and by the preview
  flow).

Behaviour notes: imports go through a confirmation preview (`/finances?action=preview`
parses + stores the rows in `$_SESSION['finance_import_preview']`, `?action=import`
consumes them). The selected account is only a fallback: `?action=import`
re-detects the file's own account via `Bank_Import::detect_account()` and uses
it as target when it matches a configured account, so a wrong selection cannot
send an export to the wrong account (the preview names the detected account).
Only the KBC format exposes its own account; Keytrade files carry no
own-account column and always fall back to the chosen one.
A file that detects to no configured account is imported into the account
chosen at preview. Warning when the file's detected account differs from the
selected one is replaced by that authoritative-target rule. Cleanup scripts:
`util/bin/cleanup_kbc.php` hard-deletes all
`bank_transaction` rows in KBC accounts (dry-run by default, `--execute` deletes),
and `util/bin/cleanup_duplicates.php` finds cross-batch duplicates — same
account + value date + amount, imported in a different batch — and archives
the newer twin (dry-run by default, `--execute` archives; soft delete, so
restorable from the transactions archive).

**Savings accounts** (`bank_account.savings`, migration
`20261106_000002_savings_bank_account`): accounts flagged as savings never
receive an import — they exist purely so their IBAN participates in the
internal-transfer check in `Bank_Transaction::is_internal_transfer()`
(a transaction connected to them is `internal` in either direction). They are
hidden from the account switcher, the import target and the transactions/stats
account filters (`Bank_Account::get_all_uploadable()`), but shown on the
settings bank-account list with a savings badge, and a savings account requires
its IBAN (validation error `mandatory_for_savings`). Importing into one is
rejected with the sticky error `savings`.

**Internal transfers** (`bank_transaction.internal`) are hidden from every
transaction list by default, so a statement never shows money moving between
own accounts: the overview pager adds `internal = 0` unconditionally, the
archive hides them unless its Transfers filter asks for them (`?internal=all`
to include, `?internal=internal` to show only those), and the statistics and
the handle queue never show them at all. Flagging them happens in two places
only — `Bank_Import::import_rows()` for rows a new import creates, and
`Bank_Reindex::run()` for every row still at `internal = 0`, which is what
repairs rows imported before the counterparty account was configured. See
`REQ-014` / `AC-025` in `spec/spec-process-finance-flow.md`.

### Handle page (uncategorized triage)

Imported transactions that no rule matched get an `incoming` flag
(`bank_transaction.incoming`, TINYINT NULL — 1 incoming, 0 outgoing, NULL not
reviewed). The import sets it from the amount sign; the migration
`20260904_000003_add_incoming_flag.php` backfilled existing uncategorized
rows. `/finances?action=handle` (`Finances::display_handle()`, template
`finances/handle.twig`, "Handle" sidebar item in both variants) lists all
uncategorized transactions across accounts; each row posts a direction select
and a category select under `transactions[<id>][incoming]` /
`transactions[<id>][category_id]` and `handle_save()` applies them — assigning
a category clears the flag again (the amount sign governs direction for
categorized transactions). The Re-index button at the top of the page posts
`?action=reindex` (`display_reindex()`, which runs
`Bank_Reindex::run()` in `lib/component/Bank/Reindex.php`) and does two
things. It re-applies the current rules to the flagged items only —
uncategorized transactions still carrying the `incoming` flag. Rules change
over time while imported rows keep the assignment they got at import; a
flagged row that now matches gets its category and the flag is cleared.
Categorized transactions are skipped by the default sweep, so manual
assignments survive a re-index (the confirm dialog says so); the component
also offers `Bank_Reindex::run(true)` for a full sweep that re-judges the
categorized transactions too and overwrites manual picks — server-side
only, it is never wired to a UI button. It also
re-checks every unflagged transaction against the current bank account list:
accounts can be configured after their transactions were imported, and those
transfers would otherwise stay `internal = 0` (and inflate the statistics)
forever. Matching rows get the internal flag and leave the review queue, their
category is kept; flagged rows are never unflagged, so manual "between my own
accounts" marks survive. Only rows whose assignment actually changes are saved.

- `lib/component/Bank/Categorizer.php` — `Bank_Categorizer`: loads all rules
  once, first-match-wins, else uncategorized. Match modes are `exact`,
  `contains`, `fuzzy` (whole-word/prefix), and `regex`; matching normalises
  both sides (deaccent, lowercase, strip non-alphanumerics). The per-rule
  field links and categories are preloaded in the constructor — they are
  lazy on the model, so uncached lookups would query the database for every
  rule on every transaction (the re-index and the import both build one
  categorizer and reuse it).

Behaviour notes: rules are global (not per-account); `sort_order` is a single
global position across all rules (migration `20261106_000001_rule_global_order`
renumbered the historical per-category values into one sequence grouped per
category). The categorizer walks the rules strictly top to bottom and stops at
the first match — a lower rule is never evaluated once an earlier rule claims a
transaction. The order is set in the rules list with drag & drop (SortableJS,
`?action=rule_order` posts `order[]` = rule ids top-to-bottom); new rules are
appended last. `import_key` uses the first 191
characters of `communication`, so two transactions identical in every key field
but differing only past char 191 of the description would collide — an accepted
edge case. Read `spec/spec-process-finance-flow.md` before changing this flow.

### Stats screen (charts dashboard)

`/finances/stats` (`Finances\Stats`, template `finances/stats.twig`,
"Statistics" sidebar item in both variants) is a read-only dashboard over the
imported transactions, scoped by three `data-keep-params` selects: account
(empty = all accounts, the default), year (defaults to the current year;
an explicitly empty value selects all years — `get_year_filter()` checks
`array_key_exists` so "All years" stays reachable) and month (1..12, only
applied together with a year; "All years" collapses and disables it). All
aggregations live in
`lib/component/Bank/Stats.php` (`Bank_Stats`, static methods taking
`?int $bank_account_id`, `?int $year` and `?int $month`): `get_totals()`
(with `avg_per_day` burn rate + `days`), `get_biggest_outgoing()`,
`get_monthly()`
(zero-filled for the 12 months of a selected year), `get_category_totals()`
(LINK JOIN on `finance_category`, uncategorized grouped under a grey row,
ordered by outgoing desc), `get_category_monthly()` (pivoted chart-ready
`periods` + `series`, top 8 categories by outgoing with the rest folded into
"Other") and `get_top_counterparties()` (top N counterparties by outgoing,
each linking to `/finances/transactions?search=<name>`). All queries exclude
archived rows and internal transfers unconditionally — the dashboard shows
nothing about internal transfers anywhere — and share a private
`get_where()`/`get_params()` pair with an optional table alias for the
joined queries and an optional `MONTH(value_date)` condition.

The charts render client-side with ApexCharts, which ships inside the tabler
npm package — load `/tabler--core/dist/libs/apexcharts/dist/apexcharts.css`
(in `header_css`) and `.min.js` (in `javascript`); no composer dependency. The
module assigns the data as plain arrays; the template embeds them with
`{{ chart_data|json_encode|raw }}` and page JS builds the charts (area chart
for monthly in/out/net, donut for spending per category — top 10 slices plus
"Other", stacked bars per category per month, horizontal bars in vs out,
breakdown table with a share-of-outgoings progress bar). Each render call is
guarded by its chart div's existence: when a month filter is active the
module skips `get_monthly()`/`get_category_monthly()` and the template drops
the two month-by-month cards. Card numbers use the `font-display` class so
they match the Fredoka page heading; chart typography is standardised (11px
axis labels, 12px legends); chart text colours are **theme-aware** —
read from `data-bs-theme` at render time via `chart_text_color()` /
`chart_muted_color()` / `chart_surface_color()` helpers, charts are
created through the `stats_chart(selector, options)` helper and
re-rendered on the `themechange` event that `base.js` dispatches on a
theme toggle (ApexCharts bakes text colours in at render, so a theme
flip without re-render leaves unreadable labels); card-header subtitles use the inline
`card-title` + small span pattern (transactions.twig style), not a separate
subtitle div.

Layout emphasis: the seven summary cards (incoming, outgoing, net, outgoing
per day, biggest expense, transactions, uncategorized) wrap in rows of three
(`col-6 col-md-4`), the spending-per-category donut is the first chart card
(`card-accent`), followed by monthly evolution, the stacked per-category
chart, in-vs-out bars next to the top-counterparties table, and the category
breakdown table as a full-width card. When the selection has no transactions
the chart grid is replaced by an empty-state card and the JS skips rendering.

### Categorization improvements (audit, teach loop, merge, conflicts, CSV)

`spec-design-categorization-flow.md` specifies five workflow features layered on the base flow (read it before changing them):

- **Rule audit**: `bank_transaction.finance_rule_id` (migration `20261109_000001_transaction_rule_audit.php`) stores which rule assigned a category. Set by import and both re-index modes via `Bank_Categorizer::categorize_with_rule()`; cleared on every manual assignment (handle, detail, CSV import). The rules list shows a per-rule match count (`Bank_Rule_Analysis::get_audit_counts()`); the detail page shows the category source. Historical rows are stamped by `util/bin/backfill_rule_audit.php` (dry-run default, `--execute`): provenance only, it writes the link when the current rules reproduce the stored category and never touches categories.
- **Teach loop**: the handle page has a per-row "create rule" checkbox; `handle_save()` appends a fuzzy rule on the counterparty (fallback: description) for the just-assigned category, skipping duplicates (`Finance_Rule::find_duplicate()`). This is how the uncategorized queue shrinks permanently.
- **Category merge**: `Finance_Category::merge_into()` moves all transactions (archived included) and active rules of a source category into a target and archives the source, atomically (`transaction_begin/commit/rollback`); UI at `?action=category_merge`. Rules keep their global sort order — only ownership moves.
- **Conflict report**: `Bank_Rule_Analysis::analyze()` (lib/component/Bank/Rule/Analysis.php — underscore maps to directories) simulates all active rules over all active non-internal transactions and reports dead rules and shadow pairs (upper category stealing from lower ones); read-only, `?action=rule_conflicts`. Takes ~10s for the current data set; acceptable for an on-demand settings action.
- **CSV round trip**: `/finances/transactions?action=export` streams the current filter as CSV (id, date, counterparty, description, communication, amount, account, category). Edit the category column offline and re-upload via `?action=import_categories` (preview in `$_SESSION['finance_category_import']`, apply via `?action=import_categories_confirm`, consumed once). Only category changes apply, matched by id + exact category name.

### Fallforce (SRT fall force calculator)

`/fallforce` (`App\Front\Module\Fallforce`, template `fallforce.twig`) is a
read-only client-side calculator: peak force of a drop on a rope of given
length and stiffness, `F = mg + √(m²g² + 2·EA·mg·h/L)`. No database, no
persistence; all rope data lives in the module's `rope_types` array
(label, `ea`, `mbs`, `knot`) and the page JS reads it from `data-*`
attributes. The page is **public** — no `secure()` method — so
`tools.buysse.io/fallforce` is shareable without login; the base layout's
navbar renders only for logged-in users (`{% if user.email is not empty %}`
wraps the whole `<header>`), which keeps app chrome off the public page.
Anonymous requests to gated areas must still 302 to `/login`. Read
`spec/spec-tool-fallforce.md` before touching the model,
the thresholds or the wording — several claims were corrected against SRT
practice and datasheets (no "FF > 0.3 is atypical" claim, the certified-hold
framing, the FF ≤ 2 physical ceiling).

Calibration: **EA is the linear datasheet value** (≈ 1.47 kN ÷ stretch at
150 kg), no stiffening factor — it closes against Beal's published EN 1891
test forces when the system absorbs ~750 J (knot tightening + harness/body).
Two red outcome boxes, both built on that 750 J: the **rope-failure box**
fires when the model peak exceeds `√(knot² + 2·(EA/L)·E_sys)`, the
**body-injury box** when the felt force
`mg + √(max(0, mg² + 2·(EA/L)·(mg·h − E_sys)))` exceeds 10 kN (6 kN is the
injury-risk guideline, but ordinary FF 1 catches on semi-static feel 7-8.5 kN
and must not alert). The rope-type select is wrapped by select2 — bind it
with jQuery `on('input change')`, a native listener never hears select2's
change (see Select2 above).

### Prompt builder (AI website prompt generator)

`/promptbuilder` (`App\Front\Module\Promptbuilder`, template
`promptbuilder.twig`) is **public** (no `secure()` method): a five-card
form that assembles a structured prompt for AI website generators and
previews it live. Ported from the standalone `../promptbuilder`
prototype (generator logic kept, chrome moved to tabler). Guided
choices are dropdowns and chip groups — never free text where a bounded
set exists; the **hosting dropdown** drives the server-config paragraph
(Apache .htaccess / nginx.conf / `_redirects` / `vercel.json` / GitHub
Pages) that the prototype hardcoded as Apache.

**History** (`prompt` table, migration `20261110_000001_prompt`,
`lib/model/Prompt.php`): signed-in users can save a generated prompt
(one row per save: title, target tool, format, the prompt `content`
and the complete form state as `inputs` JSON) and get a history panel
with Load (restores the form from the JSON) and Delete (soft
`archive()`). The history is strictly owner-scoped: anonymous calls to
`?action=save` / `?action=delete` answer 403, and delete verifies
ownership because ids are guessable. The save/delete actions answer
JSON and set `$this->template = false` so the dispatcher skips the
template render.

### Dark mode (site-wide)

Real dark mode via Tabler's `data-bs-theme` attribute on `<html>`
(`spec-design-dark-mode.md`): an inline bootstrap script in both layout
heads applies the stored (`localStorage.theme`) or system preference
before the CSS paints. `.theme-toggle` ghost buttons in the navbar
(signed-in), the footer (all pages — the public tools have no navbar)
and the login page cycle **auto → light → dark → auto** (auto = system
preference, stored as the absence of the key; icons circle-half / sun /
moon), wired from `base.js` (plain buttons — the page loader must not
fire). `base.css` must re-declare
every light-specific `:root` variable in a `[data-bs-theme="dark"]`
block **including the gray ramp** (this file loads after tabler and
would otherwise pin the light palette in both themes; tabler composes
every dark surface from its blue-tinted gray scale, so the ramp is
re-tuned green) plus explicit dark skins for the components
that hardcode light colours: plain badges, the page loader and the
select2 bootstrap-5 theme surfaces. Both `base.js` copies stay
md5-identical.

## Permissions

**Allowed without asking**: read files; run PHP Insights on a file or path; run a
specific test class; inspect the database schema.

**Ask first**: `composer require` / `composer update`; `git commit` / `git push`;
running migrations (`migrate:up` — they mutate the database);
`ALTER TABLE` or other schema changes; deleting files; running the full test
suite; anything that writes to Exact Online (bookings) or emits Peppol/UBL output.

## Commit and PR guidelines

*(Confirm these against the actual team workflow — placeholder defaults:)*

- Keep diffs small and focused.
- Run `vendor/bin/phpinsights` and the relevant tests before committing.
- Write a clear, imperative commit subject.

## When unsure

- If a convention here conflicts with the surrounding code in a file you're
  editing, match the existing code and flag the discrepancy rather than silently
  reformatting.
- Stop and ask before schema changes, migrations, or anything touching Exact
  Online bookings or Peppol output — those have real financial/legal effects.
- If tests fail repeatedly or the intent is ambiguous, ask a clarifying question
  instead of guessing.
- If something is unclear about the project's patterns, architecture, or
  conventions, ask the user before adding it to this file — don't assume.
- Treat this file as living documentation: update it in the same PR when commands,
  structure, or conventions change.

## Worked example

Touches most PHP rules at once — member layout, doc blocks, `snake_case`,
`Class_Name`, tabs + 1TBS, `===`, no useless else, no empty catch, short arrays
with trailing commas, single quotes.

```php
<?php
/**
 * Sales_Entry
 *
 * Represents a single sales entry to be booked in Exact Online.
 *
 * @author Roan Buysse <roan@tigron.be>
 */

use \Exact\Client;
use \Exact\Exception\Booking_Failed;

class Sales_Entry {
	/**
	 * Gross amount, VAT included
	 *
	 * @var float $gross_amount
	 */
	protected $gross_amount = 0.0;

	/**
	 * Book this entry via the Exact Online API
	 *
	 * Sends the gross amount in the header so the API does not report a
	 * Verschil between the header total and the summed lines.
	 *
	 * @access public
	 * @param Client $client
	 * @return string The booked entry ID
	 */
	public function book(Client $client): string {
		if ($this->gross_amount <= 0.0) {
			throw new Booking_Failed('Gross amount must be positive');
		}

		$payload = [
			'amount' => $this->gross_amount,
			'currency' => 'EUR',
		];

		try {
			$entry_id = $client->post('salesentry', $payload);
		} catch (Booking_Failed $e) {
			throw new Booking_Failed('Exact rejected the entry: ' . $e->getMessage());
		}

		return $entry_id;
	}
}
```

## File naming and tool setup

This file is `AGENTS.md` at the repository root — the cross-tool standard read by
Codex, Cursor, GitHub Copilot, and others. For tools that look for their own
filename, symlink rather than duplicating:

```bash
ln -s AGENTS.md CLAUDE.md
ln -s AGENTS.md .github/copilot-instructions.md
```

Treat this file as living documentation: update it in the same PR when commands,
structure, or conventions change.