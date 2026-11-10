---
title: Dark mode (site-wide theme)
version: 1.0
date_created: 2026-11-10
last_updated: 2026-11-10
owner: Roan Buysse
tags: [design, app]
---

# Introduction

This specification defines the site-wide dark mode: Tabler's
`data-bs-theme` theming with the application's forest-green + amber
brand palette carried into the dark surfaces, a persisted manual toggle
and a system-preference default.

## 1. Purpose & Scope

**In scope:** the theme bootstrap scripts in both layouts
(`_default/layout.base.twig`, `_default/layout.login.twig`), the toggle
buttons (navbar, footer, login page), the theme handlers in `base.js`
(both copies), and the dark palette block in `base.css`.

**Out of scope:** per-page content styling (the tools' own markup is
theme-aware through tabler variables), the finances area's internals.

## 2. Requirements

- **REQ-001**: The theme is carried as **`data-bs-theme` on `<html>`**
  (values `light` / `dark`), the mechanism Tabler ships. Both layouts
  render `data-bs-theme="light"` server-side and carry an **inline
  bootstrap script as the first thing in `<head>`** (before the CSS
  links): the stored `localStorage` preference wins, else the system
  preference (`prefers-color-scheme`), so the page never flashes the
  wrong theme and no-JS visitors get the light default.
- **REQ-002**: Toggle buttons (`.theme-toggle`) live in three places:
  the **navbar** (signed-in users, next to the user dropdown), the
  **footer** (every `layout.base` page — so public pages such as
  `/fallforce` and `/promptbuilder`, whose navbar is login-only, still
  offer the switch) and the **login page** (fixed bottom-right). They
  are plain **ghost buttons** — borderless, coloured by their context
  (white in the navbar, muted elsewhere, brand accent on hover) — and
  never links, so the page-loader overlay does not fire.
- **REQ-003**: The toggle has **three states, cycled by clicking**:
  **auto** (follow the system preference — the default, stored as the
  *absence* of the `theme` key) → **light** → **dark** → auto. The
  button shows the state: **circle-half** for auto, **sun** for light,
  **moon** for dark, with a title tooltip stating the state and what
  the next click does. While in auto, a live change of the system
  preference (matchMedia change event) updates the page without a
  click. The handlers live in `base.js` and both copies (`media/js/`
  and `media/javascript/`) stay identical.
- **REQ-004**: `base.css` declares the light brand palette at `:root`
  and this file loads after tabler — on equal specificity those values
  would beat tabler's own dark rules. Dark mode therefore **re-declares
  them — and the gray ramp itself**: tabler composes every dark
  surface from its gray scale (cards and panels from gray-800, the
  page from gray-900, muted text from gray-400) and ships a
  blue-tinted ramp, which clashes with the green brand. The dark block
  re-tunes all ten grays to green-tinted darks (page `#131b14`, cards
  `#1a241b`, text `#dfe5dd`), redeclares the brand variables on those
  surfaces (brighter green `#6fb26d` for links and focus rings,
  brightened amber `#f0b355` for `.text-amber`), and overrides the
  blue-tinted bits tabler keeps for itself: card borders
  (`--tblr-border-color-translucent`), link hover
  (tabler's dark link hover is blue), the primary
  emphasis/subtle variants (green, not blue).
- **REQ-005**: Components that hardcode light colours get explicit dark
  counterparts in `base.css`: **plain badges** (no `bg-` class) switch
  their text to light, the **page loader** overlay darkens (overlay
  wash, panel surface, text), and the **select2 bootstrap-5 theme**
  surfaces (selection box, dropdown panel, search field, options,
  highlighted option, choice pills) are re-skinned from the ramp
  variables — that theme hardcodes `#fff` / `#212529` and ships no
  dark support.
- **REQ-006**: The navbar keeps its fixed forest-green chrome in both
  themes; the **login page's black logo** is auto-inverted by tabler's
  `navbar-brand-autodark` handling once the attribute is set.
- **REQ-007**: **ApexCharts charts are themed at render time and
  re-rendered on toggle.** ApexCharts bakes text colours in when the
  chart renders and defaults to a dark gray that is unreadable on the
  dark theme. The stats page reads the current state
  (`data-bs-theme`) when building its chart options — axis labels and
  legends in the muted/text palette, donut separators in the card
  surface colour, grid lines per theme — and `base.js` dispatches a
  **`themechange` event** on `document` after every theme application
  (toggle click, system-preference change while in auto), which the
  stats page answers by destroying and re-rendering all its charts.
  Charts are created through one `stats_chart(selector, options)`
  helper (create-and-render once, `updateOptions` on later calls, so
  the expand toggle and month filter keep their old update flow).

## 3. Acceptance Criteria

- **AC-001**: Given a system set to dark and no stored preference,
  any page of the site loads dark with no light flash; setting a
  manual choice persists across pages and reloads (survives navigation
  through the login flow, the public tools and the finances area).
- **AC-002**: Given no stored preference, the site follows the system
  theme and every toggle shows the circle-half (auto) icon. Clicking
  cycles to light (sun icon), then dark (moon icon), then back to auto
  — the page follows the system again and the key is removed from
  localStorage. While in auto, changing the system preference updates
  the page live; no page-loader overlay appears during any toggle.
- **AC-003**: Given dark mode, cards, forms, tables, modals, the
  select2 dropdowns (including search and highlighted options), the
  page loader and plain badges all render on dark surfaces with
  readable text; the forest-green navbar and the amber accents stay
  brand-consistent.
- **AC-004**: Both `base.js` copies are byte-identical (md5-verified).
- **AC-005**: Given the statistics page in dark mode, the donut legend,
  donut centre labels, axis labels and chart legends render in the
  light text palette (readable), the donut separators match the card
  surface, and toggling the theme re-renders every chart with the
  other palette without reloading the page.
