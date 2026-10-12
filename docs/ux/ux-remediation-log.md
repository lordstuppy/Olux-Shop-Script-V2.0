# UX Remediation Log

Each change, in the order it was made, with its regression test and the verification. Commits are on branch `claude/zen-pasteur-vs99pr`.

- **Baseline:** `17a3380`.
- **Before-fix test results:** [evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt).

## Changes

| Finding | Commit | Files | Regression test | Before | After |
|---|---|---|---|---|---|
| UX-01 keyboard access to wide tables | e4b35b4 | 43 table boxes in 35 views (`tabindex="0"`), `enhance.js` (named region only while scrolling), `app.css` (focus outline) | `a11y.spec.js` phone project (new) with `wcag22aa`; `UxAuditTest::test_ux01_*` | 26 axe violations at 375 px | 0 |
| UX-02 confirmation for irreversible actions | e4b35b4 | New `components/confirm.blade.php`; 10 actions in 9 views; `app.css` | `ux.spec.js` "an irreversible action asks first and can be called off" (no JS); `UxAuditTest::test_ux02_*` | Deleted on the first click | Panel with the consequence; second button acts |
| UX-03 session end | e4b35b4 | `enhance.js` (signed-out message, keeps polling, renews form tokens), `layouts/app` (labels, login URL), `errors/419` | `ux.spec.js` "when the session ends ..."; `UxAuditTest::test_ux03_*` | Silent; input lost at the next submit | Message and link; the ticket is sent with the text typed before |
| UX-04 live seller pages | e4b35b4 | `LivePages` (`seller.products.index`, `seller.products.edit`, `seller.apply`) | `ux.spec.js` "staff approval reaches ..."; `UxAuditTest::test_ux04_*` (2 tests, incl. no version for another seller) | "Pending review" after 25 s | "Active" in place, same document |
| UX-05 search suggestions | e4b35b4 | `enhance.js`, `layouts/app` (label) | `ux.spec.js` "search suggestions can be used with the keyboard" | Arrow keys did nothing | Arrow keys, Escape, announced count |
| UX-06 second submits | e4b35b4 | `enhance.js` (all POST forms, 10 s or until the page changes) | `ux.spec.js` "a form sent twice ..." | 2 POSTs | 1 |
| UX-07 error summary focus | e4b35b4 | `enhance.js` | `ux.spec.js` "the error summary takes the focus ..." | Focus at the top of the document | On the summary |
| UX-08 catalogue on phones | e4b35b4, c827db2 | `products/index` ("Clear filters", class), `app.css` | `UxAuditTest::test_ux07_*`; crawl | First product at 837 px | 651 px |
| UX-09 touch targets | e4b35b4 | `partials/product-card`, `app.css` | `ux.spec.js` "a purchase can be completed on a phone" (taps the card body) | 438 below 44 px | 207 |
| UX-13 stray space | e4b35b4 | `orders/show` | `UxAuditTest::test_ux08_*` | "Paid ." | "Paid -" |
| Translation catalogue | e4b35b4, 3a382f8 | `lang/en.json` (`shop:lang-extract`) | `TranslationCatalogTest` | - | Pass |
| CI | e4b35b4 | `ci.yml` (artifact upload on failure), `playwright.config.js` (phone project, traces) | The CI job `browser` runs `ux.spec.js` and `a11y.spec.js` in 3 projects | - | - |
| UX-14 audit regression | c827db2 | `app.css` (grid limited to `.catalogue-filters`) | Crawl at 320 px | 10 px overflow on 4 staff pages | 0 |
| UX-10 landscape menus | c827db2 | `app.css` | Crawl at 812 x 375; screenshot | Only header and menu on the first screen | Menu in one sideways row |
| UX-11 fact lists | c827db2 | `app.css` | Crawl at 812 px | 2 elements past the edge on `/admin/gateway` | 0 |
| UX-12 unsaved text | c827db2 | `enhance.js` | `ux.spec.js` "leaving a page with a half-written message asks first" | No warning | Browser dialog |
| Journeys | c827db2 | `ux.spec.js`, `pages.js` (second buyer for the login limit) | Keyboard-only purchase; phone purchase; buyer order reaches the staff list | - | Pass |
| UX-22 idle-session warning | 3a382f8 | `enhance.js`, `layouts/app` (`data-session-lifetime`, labels) | `ux.spec.js` "two minutes before an idle session ends ..." (fake clock) | No warning | Warning with "Stay signed in"; the request renews the session |
| UX-23 rate-limited polls | 3a382f8 | `enhance.js` | `ux.spec.js` "a rate-limited poll slows down ..." | 2 polls in 40 s, on the way to "Connection lost" | 1 poll, no message |
| Concurrent staff decisions (evidence for U12) | after 3a382f8 | `UxAuditTest::test_u12_*` (test only; the behaviour already existed) | - | - | Second decision refused with a message |

## Verification after the changes

See [ux-regression-report.md](ux-regression-report.md):

- PHPUnit;
- the full browser suite in 3 projects;
- the crawl (584 page views);
- the simulation (101 scenarios);
- the performance gate (asset budgets);
- the security checks.

## Process notes

These are listed so the evidence can be judged.

1. **Crawl tooling, first run.** Two artefacts of the measuring script were found and corrected before any result was used.
   - Full-page screenshots reset Chromium's touch emulation, so later pages were measured with desktop pointer styles.
   - Repeated sign-ins hit the shop's login rate limit, so some "signed-in" pages were the sign-in page.
   - The crawl now signs in once per role, stores the session, takes screenshots in separate contexts, and checks that every page is signed in and on a touch pointer.
2. **Text spacing probe.** The first attempt injected a `<style>` element, which the shop's Content Security Policy blocked (72 console errors). That was correct behaviour of the shop and a wrong measurement. The check was repeated with the policy bypassed in that one test context only.
3. **Hidden content counted as visible.** Chromium keeps a layout box for the content of a closed `<details>`. The probe flagged the closed confirmation buttons as unnamed controls; it now uses `checkVisibility()`.
4. **A test bug.** The first colour-scheme test read the `<body>` background, which is transparent by design: the colour is on `<html>`. The test was fixed, not the application.
5. **Tests and native behaviour.** The keyboard journey first expected Tab to reach each radio button. Browsers give a radio group one Tab stop and use the arrow keys inside it. The test now does what a keyboard user does.
6. **Double submission not reproduced.** Duplicate records from double clicks could not be produced in Chromium, even with 400 ms of emulated latency. The finding (UX-06) rests on the observed second POST and on the missing server-side protection (UX-18), not on a reproduced duplicate.
