---
title: Promptbuilder (AI Website Prompt Builder)
version: 1.0
date_created: 2026-11-10
last_updated: 2026-11-10
owner: Roan Buysse
tags: [tool, design, app]
---

# Introduction

This specification defines the prompt builder tool: a public form that
assembles a structured prompt for AI website generators (v0, Lovable,
Bolt, Claude, ChatGPT, OpenCode, …), ported from the standalone
`../promptbuilder/index.html` prototype and extended with history
storage and extra guided dropdowns.

## 1. Purpose & Scope

**Purpose.** One place to build well-structured website prompts: guided
inputs produce a complete brief (overview, structure, design,
functionality, requirements, constraints, server config) that pastes
straight into an AI website generator.

**In scope:** the `Promptbuilder` module (`/promptbuilder`), its
template and client-side logic, the `prompt` table and `Prompt` model
(history), the navbar item and the landing card.

**Out of scope:** the finances area, the fallforce tool (own specs),
any AI integration (the tool generates text, it never calls an AI).

## 2. Requirements

- **REQ-201**: The page is **public** — the module has no `secure()`
  method — and shows the full builder to anonymous visitors. The page
  stores and reveals nothing about the visitor by itself; the navbar is
  login-only (see the fallforce spec, REQ-212 of the fallforce tool),
  so anonymous visitors get no app chrome.
- **REQ-202**: The builder form is organized in five cards, ported from
  the prototype: **project basics** (name, site type, goal, audience,
  language, description), **structure & pages** (page chips, extra
  pages, homepage sections), **design & look**, **functionality**
  (feature chips + free text) and **technical & quality**. All inputs
  carry `name` attributes so the whole form state is serializable.
- **REQ-203**: Guided choices are **dropdowns and chip selections, not
  free text wherever a bounded set exists**. Dropdowns: site type,
  overall style, color preference, imagery style, **navigation**,
  **footer**, **animation level**, **layout density**, **corner style**,
  target AI tool, output format, styling framework, tone of voice,
  **copy authorship** and **hosting target**. Chip groups: pages,
  features, quality requirements — rendered as **Bootstrap `btn-check`
  toggle buttons** (each input a direct sibling before its
  `label.btn`, unique ids) so the checked state is visibly filled;
  a checkbox hidden inside its own label toggles invisibly and must
  not return. Free text stays for names, goals,
  colors, references and extras.
- **REQ-204**: The generated prompt assembles live on every `input` and
  `change` (bound with **jQuery** — the selects are wrapped by select2,
  whose change events never reach native listeners): a fixed expert
  opening, a tool-specific technical hint with resolved styling, then
  sections per output format — narrative (sentences, lists folded into
  comma series) or structured (`##` markdown sections). The new
  dropdowns map into the prompt as Design lines (navigation, footer,
  animations, layout density, shape language), a Requirements line
  (copy authorship) and a server paragraph (hosting target).
- **REQ-205**: The **hosting target** dropdown drives the server
  configuration paragraph, replacing the prototype's hardcoded
  .htaccess demand: Apache → generate an .htaccess with the 301
  index-fix rules and comments (the prototype's behavior, and the
  default); Nginx → an nginx.conf snippet with `try_files`; Netlify →
  a `_redirects` file; Vercel → a `vercel.json` with clean URLs;
  GitHub Pages → extensionless canonical URLs with a README note;
  empty → no server paragraph at all.
- **REQ-206**: The preview column shows the generated prompt, a word /
  character count, and actions: **Copy prompt** (clipboard with
  fallback), **Download .md** (site-name slug as filename), **Reset
  form** (also unchecks the quality chips whose HTML defaults would
  otherwise survive the reset). The column is sticky.
- **REQ-207**: The **history** stores previously generated prompts, one
  row per save in the `prompt` table: `id`, `uuid`, `user_id` (FK →
  `user`), `title`, `target_tool`, `output_format`, `content` (the
  generated prompt text), `inputs` (the complete form state as JSON),
  and the standard timing columns (`created NOT NULL`, `updated` /
  `archived` nullable). Saving is a soft-delete-able record: the Delete
  action archives the row (`archived` set), nothing is hard-deleted.
- **REQ-208**: The **history is only for signed-in users**. Anonymous
  visitors see no save button, no history panel and no way to reach
  either — the `display_save()` and `display_delete()` actions answer
  403 without a session. History rows are scoped to the owner: the
  list query filters `user_id`, and the delete action verifies
  ownership before archiving (ids are guessable; the ownership check
  is what stands between users). Anonymous sign-in is offered with a
  link on the builder page.
- **REQ-209**: History rows render with title, target tool, created
  date and two actions: **Load** (restores the complete form state from
  the row's JSON — reset first, apply values, wake the select2
  wrappers with a change trigger, which also refreshes the preview)
  and **Delete** (POST with a confirm dialog, removes the row from the
  list). A prompt saved while the page is open is prepended to the
  list client-side without reloading (a reload would wipe the form).

## 3. Interfaces & Data Contracts

- `App\Front\Module\Promptbuilder` (`app/front/module/Promptbuilder.php`)
  → `/promptbuilder`; actions: `?action=save` (POST, JSON answer),
  `?action=delete` (POST, JSON answer); template `promptbuilder.twig`.
- `Prompt` model (`lib/model/Prompt.php`) — table `prompt`
  (migration `20261110_000001_prompt.php`); `get_history(user_id)`
  returns the newest 50 active prompts of one user; `archive()` /
  `restore()` soft-delete.
- Client-side: `collect_state()` serializes the form (chips as arrays),
  `apply_state()` restores it, `build_prompt()` is deterministic —
  the same inputs always generate the same prompt.

## 4. Acceptance Criteria

- **AC-201**: Given an anonymous visit to `/promptbuilder`, the full
  builder renders with all dropdowns and chips, the preview updates
  live, and there is no save button, no history panel and no navbar.
- **AC-202**: Given a signed-in user, the page shows the "Save to
  history" button and the history panel with their prompts (newest
  first), and none of another user's.
- **AC-203**: Given the defaults, the generated prompt contains the
  expert opening and the Apache .htaccess paragraph (the hosting
  dropdown's default) and ends with the "deliver the result + list
  design decisions" closing. Switching hosting to "No server config
  needed" removes the server paragraph; switching to Nginx replaces it
  with the nginx.conf snippet.
- **AC-204**: Selecting e.g. "Sidebar navigation", "Rich: link
  columns, newsletter signup, contact block" and "Subtle: fades on
  scroll" adds matching Design lines to the prompt; selecting a copy
  authorship adds a "Copy:" line under Requirements.
- **AC-205**: Given a signed-in user and a filled form, "Save to
  history" POSTs to `?action=save` and prepends the row to the list
  without a page reload; "Load" on any row restores the form exactly
  (chips included) and refreshes the preview; "Delete" asks for
  confirmation and removes the row.
- **AC-206**: Given an anonymous POST to `?action=save` or
  `?action=delete`, the answer is 403 and nothing is stored. Given a
  signed-in user POSTing a `prompt_id` owned by someone else, the
  answer is 403 and the row survives.
- **AC-207**: The landing page shows a Prompt builder card, and the
  (login-only) navbar carries a Promptbuilder item that is marked
  active on `/promptbuilder`.

## 5. Rationale & Context

The standalone prototype had the right generator but no home: no
styling system, no persistence, and several decisions that are dropdowns
in disguise (style, imagery, tone) or free text where a bounded set is
clearer (navigation, footer, animations, density, corners, copy
authorship, hosting). The port keeps the generator logic verbatim
(renamed to the codebase's snake_case), moves the chrome to the
application's tabler layout, and adds the history as a user-owned,
soft-deleted record so prompts survive beyond the clipboard. The
hosting dropdown replaces the prototype's hardcoded Apache paragraph —
the one part of the generated prompt that was actively wrong for
non-Apache deployments.
