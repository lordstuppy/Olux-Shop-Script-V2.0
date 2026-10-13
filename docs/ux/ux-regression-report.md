# UX Regression Report

**Final candidate:** `974e3f5` on branch `claude/zen-pasteur-vs99pr`, with the fixes for UX-15, UX-17, UX-18 and UX-25. Later commits add only reports and evidence. Runs from 2026-10-13; the first round of this audit ran on `66ce3a5` (2026-10-12) with the same results apart from the counts below.

## Automated results on the candidate

| Suite | What it covers | Result | Evidence |
|---|---|---|---|
| Browser suite (`tests/browser/run.sh`, fresh seed) | 39 tests: pages without JavaScript; live updates; 16 UX tests; accessibility (axe WCAG 2.2 AA, headings, labels, focus order) in light, dark and phone projects | **39 / 39 pass** (9.3 min) | [evidence/browser-suite-final.txt](evidence/browser-suite-final.txt) |
| Audit crawl | 73 pages x 4 roles x 6 viewports, light and dark: 584 page views | 0 overflow, 0 axe violations, 0 console errors, 0 failed requests, 0 unnamed controls, text spacing 0 problems | [evidence/crawl-after.json](evidence/crawl-after.json) |
| PHPUnit | 327 tests (13 new in `UxAuditTest`) | **327 / 327 pass** (4,190 assertions) | Local run |
| Simulation, production stack (nginx TLS, PHP-FPM, queue, scheduler, PostgreSQL 15, Shkeeper mock) | 101 scenarios: purchases, payments, accounts, sellers, staff, recovery, live updates | **101 / 101 pass** on the image built from `974e3f5` (app `aa9c31e6d589`, web `8a5f73838e2d`); the gift-card and balance scenarios go through the new one-time key | [evidence/simulation-ux-candidate.md](evidence/simulation-ux-candidate.md) |
| Security gate (`scripts/security-gate.sh`) | Dependencies, secrets, SAST, IaC, images, security tests | **9 / 9 PASS**; 0 HIGH or CRITICAL in both images | [evidence/security-gate-ux-candidate.json](evidence/security-gate-ux-candidate.json) |
| Static checks | Pint, PHPStan level 5, ASCII check, translation catalogue | Pass | Local run |
| Performance gate (checks that run without the stack) | Style, types, performance tests, asset budgets | PASS. `enhance.js` 29,133 B / 7,450 B gzip, against budgets of 30,000 / 8,000 | `scripts/performance-gate.sh` |
| Web Vitals A/B (old against new `enhance.js`, same server and pages) | LCP, CLS, TBT, INP (lab), transfer | No change beyond noise (LCP within 16 ms, CLS unchanged, TBT within 18 ms, the catalogue lower); +8.7 KB transfer uncompressed, about +2 KB with gzip | [evidence/webvitals-ab.json](evidence/webvitals-ab.json) |

**Revisions of the evidence:**

- **Simulation and security gate:** both ran on `974e3f5`. The gate's working tree held this round's report edits (`working_tree_dirty: true`) and scanned the simulation's images.

## Before and after

| Measure | Before (`17a3380`) | After (`974e3f5`) |
|---|---|---|
| UX regression tests | 6 of 8 initial tests fail, plus 4 later ones ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt)) | 16 / 16 pass |
| axe WCAG 2.2 AA violations, crawl | 26 | 0 |
| Browser suite size | 19 tests (axe without `wcag22aa`, no phone project) | 39 tests |
| Touch targets below 44 px on touch viewports | 438 | 207 |
| Catalogue, first product at 375 px | 837 px | 651 px |
| Irreversible one-click actions | 10 | 0 |
| Pages that need a reload to show staff decisions | 3, plus the menus after a role change | 0 |

## Visual review

Screenshots of every page at 375 and 1280 px, in light and dark, were taken in the baseline and after crawls.

**Looked at by eye (no other screenshots were reviewed one by one):**

| Page | Width | Before | After |
|---|---|---|---|
| Catalogue | 375 px | Yes | Yes (two-column filters) |
| Order page | 375 px | Yes | - |
| Staff gateway page | 812 x 375 landscape | Yes (the menu filled the screen) | - |
| Seller product page with the opened confirmation panel | 375 px | - | Yes, light and dark (readable panel, clear second button) |

No unintended visual change was seen in these. For all other pages, the after crawl checks the layout properties automatically: overflow, elements past the edge, axe including contrast, and target size.

**No screenshot comparison in CI:** pixel diffs of a server-rendered, data-dependent shop would mostly flag data. CI instead checks layout properties (overflow, axe, target size) on every page.

## CI enforcement

- **Job:** `.github/workflows/ci.yml`, job `browser`.
- **What it runs:** the whole browser suite on every push and pull request, after the `php` job.
- **When it fails:** a failing UX test, an accessibility violation in any of the three projects, a console error, or a page that does not render without JavaScript fails the job.
- **Evidence kept:** screenshots and traces of failures are uploaded as the artifact `browser-test-results` (14 days).
- **Making it mandatory:** the job must be a required check in the repository settings, together with `php`, `security` and `performance`. That setting is outside the repository and has to be made by the owner.

## Remaining issues and untested behaviour

- **Open findings:** only the remaining part of UX-09 (Low). UX-16 (UTC only) is accepted by the owner. See [ux-findings.md](ux-findings.md).
- **Not tested:**
  - screen readers;
  - real users;
  - Safari and Firefox;
  - real phones (safe areas, on-screen keyboards);
  - forced colours.
- **Asset budget:** `enhance.js` uses 97 % of its raw and 93 % of its gzip budget, after removing duplicated code to absorb UX-15. The next client feature needs either a budget decision or a split, for example loading the live-update code only for signed-in users.
