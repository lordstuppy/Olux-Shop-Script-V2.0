# UX, UI Quality and Usability Audit: Summary

**Decision: BLOCKED - DO NOT SHIP.**

The shop's journeys work and are now covered by tests:

- for every role;
- with and without JavaScript;
- on a phone and by keyboard alone.

All 3 High and 4 Medium findings are fixed, with regression tests that failed before the fixes. Every automated check passes on the candidate.

The decision is BLOCKED because two mandatory gates need something this environment does not have:

| Gate | Status | What it needs |
|---|---|---|
| U8 Accessibility | BLOCKED | A screen-reader test (NVDA + Firefox, VoiceOver + Safari). Automated scans alone do not establish WCAG conformance; the script to run is in [ux-accessibility-report.md](ux-accessibility-report.md#screen-reader-script). |
| U14 Real-world testing | BLOCKED | The screen-reader part of the journeys, and sessions with real users |

The security audit's BLOCKED decision ([docs/security](../security/security-audit-summary.md)) and the performance audit's ([docs/performance](../performance/performance-audit-summary.md)) still apply.

## Tested revision

- **Baseline:** `17a3380`.
- **Candidate:** `66ce3a5` on branch `claude/zen-pasteur-vs99pr`. Later commits add only reports and evidence.
- **Simulation image:** app `7596c7c4ee64` and web `c401e0174446`, built from `c827db2`. The two later commits change only JavaScript and one label, which the script-free simulation does not use.

## Gates

**15 gates: 13 PASS, 2 BLOCKED, 0 FAIL, 0 NOT VERIFIED, 0 NOT APPLICABLE.**

Machine-readable: [ux-gates.json](ux-gates.json).

| Gate | Status | Basis |
|---|---|---|
| U1 Inventory | PASS | 85 pages, 105 actions, 30 critical journeys, each mapped to a test ([ux-workflow-inventory.md](ux-workflow-inventory.md)) |
| U2 Interaction feedback | PASS | Confirmation for 10 irreversible actions; second submits dropped; feedback from the server only |
| U3 Forms and passwords | PASS | Password toggle by keyboard; autocomplete and input modes; error summary focused; unsaved text guarded |
| U4 Real-time sync | PASS | Polling every 10 s on 26 pages; staff-to-user and user-to-staff updates tested; session end and reconnect handled ([ux-realtime-sync-report.md](ux-realtime-sync-report.md)) |
| U5 Notifications | PASS | Toasts only for real events, once across tabs; centre keeps everything; no false "connection lost" |
| U6 Responsive | PASS | 6 viewports including landscape: 0 overflow, text spacing passes, purchase completed on a phone ([ux-responsive-audit.md](ux-responsive-audit.md)) |
| U7 Themes | PASS | Light, dark and device; no flash of the wrong theme; contrast checked in both |
| U8 Accessibility | **BLOCKED** | axe WCAG 2.2 AA 26 -> 0; keyboard-only purchase. No screen reader available. |
| U9 Navigation | PASS | Current page marked; deep links survive sign-in; useful error pages; filters in the URL |
| U10 State | PASS | Server is the single source of truth; no optimistic updates; concurrent decisions refused with a message |
| U11 Loading, empty, offline, errors | PASS | Busy indicator; empty states with a next step; connection and session messages |
| U12 Roles | PASS | Five staff roles; confirmations; live staff lists; stale decisions refused |
| U13 Content | PASS | One locale (English), all strings translatable; wording fixes; RTL not applicable |
| U14 Real-world testing | **BLOCKED** | Browser journeys and 101 simulation scenarios pass. No screen reader and no real users. |
| U15 Final regression | PASS | See "Regression" below |

## Findings

**24 findings** ([ux-findings.md](ux-findings.md)):

| Severity | Count | Status |
|---|---|---|
| High | 3 | Fixed |
| Medium | 4 | Fixed (UX-22 needs JavaScript) |
| Low | 14 | 9 fixed, 1 in part, 4 open |
| Info | 3 | Decisions, or not verifiable here |

**The ones that mattered most:**

- **UX-01 (High):** wide tables on phones could not be scrolled with the keyboard. That is a WCAG level A failure on 12 seller and staff pages; the old scan ran only at desktop width.
- **UX-02 (High):** 10 irreversible actions ran on a single click, among them cancelling an order, deleting a category and resetting an edited email template. They now ask first, without needing JavaScript.
- **UX-03 (High):** when a session ended, the page did not say so, and typed text was lost at the next submit. Now the page says so, and the text can be sent after signing in again in another tab.
- **UX-04 (Medium):** after a staff decision, the seller saw the notification while the list on screen still showed the old status. The application page even promised to show the decision.
- **UX-22 (Medium):** the 120-minute idle limit came without a warning (WCAG 2.2.1). The page now warns 2 minutes ahead and offers to stay signed in.

**Open, all Low:**

- the header navigation after a role change (UX-15);
- times in UTC only (UX-16);
- optional fields not marked consistently (UX-17);
- no server-side duplicate key for two staff money forms (UX-18; not reproduced, and the browser now blocks double submits).

## Workflow coverage

**Journeys:** 30 critical journeys (buyer 16, seller 6, staff 8), each verified by:

- a browser test;
- a PHPUnit test;
- or one of the 101 simulation scenarios.

**On a phone:** the purchase runs end to end. Every page is checked at 320, 375 and 768 px and in landscape.

**By keyboard alone:** sign-in to payment.

## Accessibility

- **axe-core (WCAG 2.2 AA):** 0 violations on 73 pages at 375 and 1280 px, in light and dark (26 before), and in CI in three projects.
- **Keyboard and focus:** checked by scripts.
- **Screen readers:** not tested. U8 is therefore BLOCKED and no conformance claim is made.

## Responsive results

**584 page views:**

- 0 page overflow;
- 0 elements past the edge;
- 0 console errors;
- 0 unnamed controls;
- text spacing passes.

**On phones:**

- the first product is 186 px higher on the catalogue;
- touch targets below 44 px fell from 438 to 207;
- the landscape admin menu no longer fills the screen.

## Synchronisation

**Mechanism:** polling, documented and tested:

- staff to user (payment, ticket reply, product approval);
- user to staff (new order);
- several tabs;
- connection loss and recovery;
- session end, the idle warning and the rate limit.

Version checks follow the same access rules as the pages.

## Regression

| Check | Result |
|---|---|
| Browser suite | 37 / 37 (was 19 tests) |
| PHPUnit | 322 / 322 |
| Simulation | 101 / 101 |
| Security gate | 9 / 9 PASS |
| Performance gate | Asset budgets PASS |
| Web Vitals A/B | Unchanged within noise |
| Pint, PHPStan, ASCII | Clean |

**Regression of this audit:** one, caught by its own crawl and fixed before release (UX-14).

**Asset budget:** `enhance.js` now uses 98 % of its budget.

Details: [ux-regression-report.md](ux-regression-report.md).

## Checks not performed

| Check | Why not |
|---|---|
| Screen readers | None available in the container |
| Usability sessions or analytics with real users | Not available |
| Safari, Firefox, real devices | Chromium only, with device emulation |
| Forced colours or Windows high contrast | Not available |
| Freshness on real networks at scale | See the performance audit (P10) |

## Exceptions

**No exception was approved.** The audit does not approve its own exceptions.

## To reach PASS

1. Run the screen-reader script in [ux-accessibility-report.md](ux-accessibility-report.md#screen-reader-script) with NVDA + Firefox and VoiceOver + Safari (iOS), and record the results. This unblocks U8 and the screen-reader part of U14.
2. Hold at least a few usability sessions on the buyer purchase and the seller listing journeys, or decide formally that U14's user sessions are not required for this release.
3. Optionally, before or after release: decide UX-16 (time zone) and fix UX-15, UX-17 and UX-18.
4. Make the CI job `browser` a required check, together with `php`, `security` and `performance`.
