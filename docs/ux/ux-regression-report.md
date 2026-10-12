# UX Regression Report

**Final candidate:** `66ce3a5` on branch `claude/zen-pasteur-vs99pr`. Later commits add only this report, the gate file and evidence. All runs below are from 2026-10-12.

## Automated results on the candidate

| Suite | What it covers | Result | Evidence |
|---|---|---|---|
| Browser suite (`tests/browser/run.sh`, fresh seed) | 37 tests: pages without JavaScript; live updates; 14 UX tests; accessibility (axe WCAG 2.2 AA, headings, labels, focus order) in light, dark and phone projects | **37 / 37 pass** (8.6 min) | [evidence/browser-suite-final.txt](evidence/browser-suite-final.txt) |
| Audit crawl | 73 pages x 4 roles x 6 viewports, light and dark: 584 page views | 0 overflow, 0 axe violations, 0 console errors, 0 failed requests, 0 unnamed controls, text spacing 0 problems | [evidence/crawl-after.json](evidence/crawl-after.json) |
| PHPUnit | 322 tests (8 new in `UxAuditTest`) | **322 / 322 pass** (4,165 assertions) | Local run |
| Simulation, production stack (nginx TLS, PHP-FPM, queue, scheduler, PostgreSQL 15, Shkeeper mock) | 101 scenarios: purchases, payments, accounts, sellers, staff, recovery, live updates | **101 / 101 pass** on the image built from `c827db2` (app `7596c7c4ee64`) | [evidence/simulation-ux-candidate.md](evidence/simulation-ux-candidate.md) |
| Security gate (`scripts/security-gate.sh`) | Dependencies, secrets, SAST, IaC, images, security tests | **9 / 9 PASS**; 0 HIGH or CRITICAL in both images | [evidence/security-gate-ux-candidate.json](evidence/security-gate-ux-candidate.json) |
| Static checks | Pint, PHPStan level 5, ASCII check, translation catalogue | Pass | Local run |
| Performance gate (checks that run without the stack) | Style, types, performance tests, asset budgets | PASS. `enhance.js` 29,437 B / 7,668 B gzip, against budgets of 30,000 / 8,000 | `scripts/performance-gate.sh` |
| Web Vitals A/B (old against new `enhance.js`, same server and pages) | LCP, CLS, TBT, INP (lab), transfer | No change beyond noise (for example catalogue LCP 364 -> 352 ms, TBT 31 -> 27 ms); +9.0 KB transfer uncompressed, about +2 KB with gzip | [evidence/webvitals-ab.json](evidence/webvitals-ab.json) |

**Revisions of the evidence:**

- **Simulation:** its image was built before the last two commits. Those changed `enhance.js` and one label; the Python-driven simulation runs no JavaScript.
- **Security gate:** ran at `66ce3a5` with this audit's untracked evidence files in the tree (`working_tree_dirty: true`), on the simulation's images.

## Before and after

| Measure | Before (`17a3380`) | After (`66ce3a5`) |
|---|---|---|
| UX regression tests | 6 of 8 initial tests fail, plus 2 later ones ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt)) | 14 / 14 pass |
| axe WCAG 2.2 AA violations, crawl | 26 | 0 |
| Browser suite size | 19 tests (axe without `wcag22aa`, no phone project) | 37 tests |
| Touch targets below 44 px on touch viewports | 438 | 207 |
| Catalogue, first product at 375 px | 837 px | 651 px |
| Irreversible one-click actions | 10 | 0 |
| Pages that need a reload to show staff decisions | 3 | 0 (the header after a role change: UX-15, open) |

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

- **Open findings:** UX-15, UX-16, UX-17 and UX-18 (Low), and the remaining part of UX-09. See [ux-findings.md](ux-findings.md).
- **Not tested:**
  - screen readers;
  - real users;
  - Safari and Firefox;
  - real phones (safe areas, on-screen keyboards);
  - forced colours.
- **Asset budget:** `enhance.js` uses 98 % of its budget. The next client feature needs either a budget decision or a split, for example loading the live-update code only for signed-in users.
