---
title: Topos (cave survey library / data contract)
version: 1.0
date_created: 2026-11-10
last_updated: 2026-11-10
owner: Roan Buysse
tags: [process, data, tool, app]
---

# Introduction

This specification defines the topos area of the tool: a library of cave surveys
("topos"). The files keep living on the Synology NAS (`/volume1/homes/roanb/Documents/Speleo/Topo's`,
reachable over HTTPS at `https://ds.buysse.io`); the tool never copies originals.
It stores only a card-system of metadata in MySQL: which caves exist, which files
belong to which cave, how to reach each file. The cave catalogue is seeded from
Grottocenter search exports; the file index is built by walking the NAS folder
through Synology's own web API (FileStation). End result: a browsable, filterable
list of caves, each with links to its relevant files on the NAS.

Written for AI agents and the developer, so any change keeps the existing
invariants (idempotent imports, incremental scans, stable cave↔file links).

## 1. Purpose & Scope

**Purpose.** One place describing the topos data model, the two importers
(Grottocenter catalogue, NAS file index), the cave↔file matching rules and the
user-facing pages.

**In scope:** the `Topos` module (`/topos`) and `Topos\Settings` (`/topos/settings`),
the `topo_cave` / `topo_file` tables, `Topo_*` models and `Topo_*` components
(Grottocenter import, FileStation client, scanner, matcher).

**Out of scope (later stages, keep the schema room for them):** GPS entrance map
view, automatic per-cave bundles (zip/printable PDF), PDF page counts/thumbnails
of PDFs. Coordinates and file links are already stored so these stages add no
migration.

**Audience.** AI coding agents and the developer.

## 2. Definitions

| Term | Definition |
|---|---|
| Topo | A file containing a cave survey or related document (PDF, scan, SVG, …). |
| Cave | One physical cave or cave system; the unit the topos are organised by. |
| Grottocenter | The public cave database (grottocenter.org); the source of the seed catalogue. |
| FileStation | Synology DSM's documented web API (`SYNO.FileStation.*`, `SYNO.API.Auth`), used through `https://ds.buysse.io/webapi/entry.cgi`. |
| Relative path | A file's path below the configured NAS root folder, e.g. `Emotions/Grotte des Emotions.pdf`. The stable identity of a file in the index. |

## 3. Requirements, Constraints & Guidelines

### Data model

- **REQ-001**: Table `topo_cave` holds the cave catalogue. Columns in order: `id`,
  `uuid`, `grottocenter_id` (nullable — manually created caves have none),
  `name`, `location`, `country`, `latitude`, `longitude`, `depth_m`, `length_m`,
  `data_quality`, `grottocenter_url`, `notes`, timing columns. Unique key on
  `grottocenter_id`; index on `name`.
- **REQ-002**: Table `topo_file` holds the NAS file index. Columns in order: `id`,
  `uuid`, `topo_cave_id` (nullable FK — unindexed files are in the review queue),
  `relative_path`, `filename`, `mime_type`, `size_bytes`, `sha256`,
  `nas_modified`, timing columns. Unique key on `relative_path` (the NAS path is
  the stable identity); key + FK on `topo_cave_id`; index on `sha256`.
- **REQ-003**: Magic columns (`uuid`, `created`, `updated`, `archived`) follow the
  repository conventions: `created` NOT NULL, `updated`/`archived` nullable with
  default NULL. Soft delete via `archive()`; no hard deletes except a file row
  whose relative path disappeared from the NAS, which is **archived, not
  deleted**, so a temporary unplug never wipes the library.

### Grottocenter import

- **REQ-101**: The importer accepts the Grottocenter search exports **as files**
  (GeoJSON is the master format; CSV is a supplement). Parsing must be
  format-driven by content, permissive on column order and extras.
- **REQ-102**: Upsert by `grottocenter_id` parsed from `grottocenterUrl`
  (`…/entrances/<id>`). A CSV row with no URL matches an existing cave by
  (name, location) pair; only creates a new cave when no match exists (and then
  leaves `grottocenter_id` NULL). Re-running the import therefore updates and
  never duplicates (same dedup philosophy as the bank import).
- **REQ-103**: `Depth` / `Length` are cast to int when numeric and stored NULL
  otherwise. `Country` "BE - België" is stored as-is (display value); coordinates
  come from the GeoJSON `Point` geometry (`[longitude, latitude]`).
- **REQ-104**: The import never overwrites a manually entered field with an
  empty/zero incoming value: fields present in the export update; absent or
  empty fields keep the stored value.

### NAS indexing

- **REQ-201**: The scanner walks the configured root folder
  (`/volume1/homes/roanb/Documents/Speleo/Topo's`) recursively through the
  FileStation API against `https://ds.buysse.io`, using a **restricted read-only
  DSM account** created for this tool. Credentials live in
  `config/environment.php` under `nas` (`base_url`, `account`, `password`,
  `folder_path`) — gitignored, never in code or committed config (same rule as
  the Exact Online credentials).
- **REQ-202**: The scan is **incremental and stable-identified**: a file is
  processed (downloaded for hashing) only when its `relative_path` is new or its
  NAS-reported `size`/`modified` differ from the stored row. The web action
  processes a bounded number of directories per request (cursor by folder) so an
  HTTP request never runs unbounded; the CLI script `util/bin/topos_scan.php`
  walks fully for scheduled runs.
- **REQ-203**: New/changed files are hashed SHA-256, mime-typed from their first
  bytes (`finfo`), and stored with `nas_modified` from the NAS reply. `page_count`
  and PDF thumbnails are deliberately not part of stage 1.
- **REQ-204**: A `relative_path` that no longer exists in the NAS listing gets
  its row **archived** (not deleted), so the cave association survives a scan
  misstep. Files inside archived cave folders… follow their cave: archiving a
  cave does not archive its files (files keep `topo_cave_id`; the cave just
  disappears from the gallery).

### Cave ↔ file matching

- **REQ-301**: At scan time each file (and its parent folders) is matched against
  the cave catalogue with the same normalisation the finance categorizer uses
  (deaccent, lowercase, strip non-alphanumerics). Match order: exact normalized
  equality of a parent-folder name to a cave name (strong), then cave name
  contained in file name, then file name contained in cave name; a `location`
  bonus breaks ties. First match wins.
- **REQ-302**: The scan **only fills a NULL `topo_cave_id`** — a manual
  assignment (or an earlier accepted match) is never silently overwritten.
  Re-indexing from the settings page may re-run matching explicitly, mirroring
  `Bank_Reindex` semantics: default sweep touches unassigned files only.
- **REQ-303**: The settings page lists unassigned files with a per-file cave
  select (assign) — this is the review queue; matched files may be reassigned
  manually there as well.

### Pages & privacy

- **REQ-401**: `/topos` (overview, tiny sidebar) shows a gallery of **caves that
  have at least one active file** as cards (name, location, country, file count);
  a switcher/filter offers the full catalogue (all caves, also without files) and
  a search. `/topos?action=detail&topo_cave_id=X` shows one cave with its files
  (icon per type; browsable types stream inline, others download).
- **REQ-402**: The whole area is login-gated (`secure()`), like the finances:
  Grottocenter-derived entrance coordinates of Belgian caves are semi-sensitive
  within the scene and are not exposed to anonymous visitors.
- **REQ-403**: `/topos?action=file&topo_file_id=X` streams the original from the
  NAS through the FileStation client, honouring the mime type; it never lists or
  reveals the full NAS path beyond the relative path, and requires login like
  every other topos page.
- **REQ-404**: `/topos/settings` (full sidebar) holds: caves list (paged, add/edit),
  unassigned-files review queue, "Import Grottocenter" upload (the two export
  files as uploaded files or via re-running on `util/tmp` paths with the CLI
  script `util/bin/topos_import.php`), and the NAS scan trigger + connection
  status (configured yes/no, last scan info stored in a `topo_scan_log`-style
  stub or simply as settings note — stage 1 keeps a minimal `last_scan` info row
  per option: JSON in a `topo_setting` key/value table is overkill; keep a single
  row utilising `topo_cave`-free mini table **not** introduced — last-scan
  feedback is session-sticky, nothing persisted).

## 4. Interfaces & Data Contracts

### Schema (migration `20261110_000002_topos.php`)

```sql
CREATE TABLE `topo_cave` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) DEFAULT NULL,
    `grottocenter_id` INT(11) UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `location` VARCHAR(255) NOT NULL DEFAULT '',
    `country` VARCHAR(100) NOT NULL DEFAULT '',
    `latitude` DECIMAL(10,6) DEFAULT NULL,
    `longitude` DECIMAL(10,6) DEFAULT NULL,
    `depth_m` INT(11) UNSIGNED DEFAULT NULL,
    `length_m` INT(11) UNSIGNED DEFAULT NULL,
    `data_quality` INT(11) DEFAULT NULL,
    `grottocenter_url` VARCHAR(255) DEFAULT NULL,
    `notes` MEDIUMTEXT DEFAULT NULL,
    `created` DATETIME NOT NULL,
    `updated` DATETIME DEFAULT NULL,
    `archived` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `grottocenter_id` (`grottocenter_id`),
    KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `topo_file` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) DEFAULT NULL,
    `topo_cave_id` INT(11) UNSIGNED DEFAULT NULL,
    `relative_path` VARCHAR(255) NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    `size_bytes` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `sha256` CHAR(64) DEFAULT NULL,
    `nas_modified` DATETIME DEFAULT NULL,
    `created` DATETIME NOT NULL,
    `updated` DATETIME DEFAULT NULL,
    `archived` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `relative_path` (`relative_path`),
    KEY `topo_cave_id` (`topo_cave_id`),
    KEY `sha256` (`sha256`),
    CONSTRAINT `topo_file_ibfk_1` FOREIGN KEY (`topo_cave_id`)
        REFERENCES `topo_cave` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Components

| Component | Responsibility |
|---|---|
| `Topo_Grottocenter_Import` (`lib/component/Topo/Grottocenter/Import.php`) | `run(string $geojson_path, ?string $csv_path = null): array` — parses, upserts, returns `['imported', 'updated', 'skipped']`. |
| `Topo_Filestation_Client` (`lib/component/Topo/Filestation/Client.php`) | `login()`, `list(string $path): array`, `download(string $path): string`, `logout()`. Plain curl, sid-based session. |
| `Topo_Scanner` (`lib/component/Topo/Scanner.php`) | Walks root folder (full or cursor-bounded), applies REQ-202/203, calls the matcher, returns `['listed', 'created', 'updated', 'skipped', 'archived']`. |
| `Topo_Matcher` (`lib/component/Topo/Match.php`) | `guess(string $candidate_name, string $parent_folder_name): ?Topo_Cave` — normalization per REQ-301, first match wins. |

### Module actions

| Module / route | Actions |
|---|---|
| `App\Front\Module\Topos` `/topos` | `display` (gallery), `detail`, `file` (stream), `map` (stage 2, not wired yet) |
| `App\Front\Module\Topos\Settings` `/topos/settings` | `display` (caves), `cave_new`, `cave_edit`, `cave_archive`, `unassigned` (review queue), `unassigned_save`, `import_grottocenter`, `scan` |

### Config (`config/environment.php`, gitignored)

```php
'nas' => [
    'base_url' => 'https://ds.buysse.io',
    'account' => '<restricted read-only DSM account>',
    'password' => '<…>',
    'folder_path' => '/volume1/homes/roanb/Documents/Speleo/Topo\'s',
],
```

## 5. Acceptance Criteria

- **AC-101**: Given the two export files, When the import runs and then runs
  again, Then the second run reports `imported = 0` and the cave count is
  unchanged (≈ 9,816 + the 5 CSV-only rows).
- **AC-102**: Every GeoJSON-imported cave carries non-null latitude/longitude
  and its Grottocenter url; the 5 CSV-only rows import without coordinates and
  without a Grottocenter id.
- **AC-103**: An export row with non-numeric `Depth` (e.g. `""`) stores depth NULL
  and does not abort the import.
- **AC-201**: Given an indexed file row for relative path P with matching size
  and NAS modified timestamp, When the scan runs again, Then the file is reported
  `skipped` and is not re-downloaded (no new sha256 computation).
- **AC-202**: Given a file removed from the NAS between scans, When the scan
  runs, Then the row is archived — still visible with its cave link through the
  archived filter, still restorable.
- **AC-301**: Given cave "Grotte des Émotions" (location "My") and a NAS file in
  folder `Emotions/`, When the scan matches, Then the file is assigned to that
  cave by folder-name match. A file already manually assigned to another cave is
  left untouched by the same scan.
- **AC-401**: Given an anonymous visitor, Every `/topos*` URL redirects to
  `/login`.
- **AC-402**: Given a cave with 3 files, The gallery card shows file count 3 and
  the detail page streams each file inline (PDF sampled, download forced for
  unguessable types) without ever exposing the full `/volume1` path.
- **AC-403**: Re-uploading the same GeoJSON via settings (or re-running the CLI)
  produces no second cave — updates the existing rows only.

## 6. Test Automation Strategy

- **Test Levels**: unit for parsers/normalizers (Grottocenter import field
  mapping, matcher normalization, scanner delta logic), integration for the
  importer round trip against a small fixture export, end-to-end for module
  flows through `util/bin/skeleton test:run`.
- **Test Data Management**: a trimmed GeoJSON fixture (5 caves incl. the
  CSV-only rows) committed alongside the test.

## 7. Rationale & Context

The NAS folder is organised (and browsable) but invisible from the tools site and
searchable by nothing; Grottocenter provides the authoritative Belgian cave list
with coordinates that the NAS filenames alone could never supply. The two-sided
design — catalogue from Grottocenter, files indexed in place — keeps the NAS the
single source of truth for binaries while making the whole knowledge queryable.
Incremental hashing by (path, size, mtime)³ keeps even a "massive" directory
cheap after the first pass.

## 8. Dependencies & External Integrations

### External Systems
- **EXT-001**: Synology DSM at `https://ds.buysse.io` — FileStation API over
  HTTPS for listing and download; requires a restricted DSM account.
- **EXT-002**: grottocenter.org — source of the seed catalogue (via exported
  files, no live API dependency in stage 1).

### Data Dependencies
- **DAT-001**: `util/tmp/Grottocenter_search_export_*.geojson` (9,816 Belgian
  caves, all with coordinates + entrance url) — master import.
- **DAT-002**: `util/tmp/Grottocenter_search_export_*.csv` (9,813 rows; adds 5
  rows missing from the GeoJSON) — supplement, (name, location) respected.

### Technology Platform Dependencies
- **PLT-001**: PHP `ext-curl` (FileStation HTTPS calls), `ext-json`, `PCRE`,
  `finfo` — standard on the deployment PHP.

### Compliance Dependencies
- **COM-001**: Cave entrance coordinates are treated as access-sensitive: the
  whole topos area stays behind the login.

## 9. Examples & Edge Cases

```php
// Grottocenter feature → cave (GeoJSON)
[
    'properties' => [
        'Name' => 'Émotions (Grotte des)',
        'City' => 'My',
        'Depth' => 45,
        'Data quality' => 24,
        'grottocenterUrl' => 'https://grottocenter.org/ui/entrances/168740',
    ],
    'geometry' => ['coordinates' => [5.567, 50.4]],   // [lon, lat]
]
// → topo_cave row: name='Émotions (Grotte des)', location='My',
//   depth_m=45, latitude=50.4, longitude=5.567,
//   grottocenter_id=168740, grottocenter_url=<url>, data_quality=24

// Edge cases
// - Name with accent: matching normalizes 'Émotions' → 'emotiones'
// - Depth "" → NULL, never 0
// - Orientation of geometry: feature order is [longitude, latitude]
// - CSV-only row: cave created with location, depth, length, quality;
//   latitude/longitude/grottocenter_id stay NULL
```

## 10. Validation Criteria

- Migration applies and rolls back cleanly (`migrate:up` / `migrate:down`).
- Import CLI idempotence check (run twice, compare counts — AC-101).
- `php -l` and scoped unit test for the importer fixture pass.

## 11. Related Specifications / Further Reading

- `AGENTS.md` — repository conventions (naming, magic columns, decimal rule).
- `spec-process-finance-flow.md` — the dedup/incremental-import/soft-delete
  patterns this flow mirrors.
- Synology FileStation API (`SYNO.FileStation.*`) — external documentation.
