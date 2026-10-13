# UX Findings

**Scope:**

- **Code and configuration:** the whole application at `17a3380` (baseline) and the candidate described in [ux-audit-summary.md](ux-audit-summary.md).
- **Method:** the real application was run with demo data and driven in Chromium (Playwright), with and without JavaScript, as guest, buyer, seller and admin.
- **Evidence:**
  - the page crawl ([evidence/crawl-baseline.json](evidence/crawl-baseline.json), [evidence/crawl-after.json](evidence/crawl-after.json));
  - the regression tests that failed before the fixes ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt));
  - the browser suite results ([ux-regression-report.md](ux-regression-report.md)).

**Severity:**

- **High:** blocks or misleads users in a critical journey, or is a WCAG level A failure there.
- **Medium:** causes friction or a wrong impression that users can recover from.
- **Low:** polish, or an edge case.
- **Info:** a decision, or something observed but not a defect.

## Overview

| ID | Title | Severity | Gate | Status |
|---|---|---|---|---|
| UX-01 | Wide tables on phones cannot be scrolled with the keyboard | High | U8, U6 | Fixed |
| UX-02 | Irreversible actions run on a single click, without confirmation | High | U2 | Fixed |
| UX-03 | A session that ends is not shown; typed input is lost on the next submit | High | U4, U11 | Fixed |
| UX-04 | Seller pages stay stale after staff decisions, while a notification announces them | Medium | U4, U10 | Fixed |
| UX-05 | Search suggestions cannot be reached with the arrow keys and are not announced | Medium | U8 | Fixed |
| UX-06 | Only payment forms are protected against a second submit | Medium | U2, U3 | Fixed (client; server part: UX-18) |
| UX-22 | No warning before an idle session ends (WCAG 2.2.1) | Medium | U8, U11 | Fixed with JavaScript; no warning without it |
| UX-25 | Typing in the header search stopped live updates on that page | Medium | U4, U10 | Fixed |
| UX-07 | After a failed submission the focus stays at the top of the page | Low | U3, U8 | Fixed |
| UX-08 | Catalogue filters fill the first phone screen; no "Clear filters" once there are results | Low | U6, U9 | Fixed |
| UX-09 | Touch targets below the project's 44 px goal (logo, product cards) | Low | U6, U8 | Fixed in part |
| UX-10 | In landscape on a phone, header and section menu fill the whole first screen | Low | U6 | Fixed |
| UX-11 | Fact lists overflow narrow sidebar cards at 769 to about 1000 px | Low | U6 | Fixed |
| UX-12 | Leaving a page with half-written text loses it without a warning | Low | U3 | Fixed |
| UX-13 | Stray space in the order status line ("Paid .") | Low | U7 | Fixed |
| UX-23 | Many open windows: the rate-limited live poll claimed "Connection lost" | Low | U4, U5 | Fixed |
| UX-24 | The sound setting says "In this browser" but applies to the account | Low | U5 | Fixed |
| UX-14 | Regression found during the audit: catalogue grid squeezed staff date filters at 320 px | Low | U15 | Fixed before release |
| UX-15 | Header navigation follows a role change only on the next page load | Low | U12 | Fixed |
| UX-16 | All times are shown in UTC, not in the visitor's time zone | Low | U13 | Accepted: UTC stays (owner decision, 2026-10-13) |
| UX-17 | Optional fields are not marked consistently | Low | U3 | Fixed |
| UX-18 | No server-side duplicate protection for staff balance adjustments and gift-card creation | Low | U2 | Fixed |
| UX-19 | Colour scheme is stored per browser, set only in the footer | Info | U7 | Decision |
| UX-20 | Two search fields on the catalogue page | Info | U1 | Decision |
| UX-21 | No screen reader and no real users were available for testing | Info | U8, U14 | Not verified |

**Counts:**

- High 3 (all fixed);
- Medium 5 (all fixed; UX-22 only with JavaScript);
- Low 14 (12 fixed, 1 in part, 1 accepted by the owner: UX-16);
- Info 3.

**Open:**

- the remaining part of UX-09 (Low): links in table cells and notification lists below the 44 px goal, within AA;
- none High or Medium.

**Total:** 25 findings.

## Details

### UX-01: Wide tables on phones cannot be scrolled with the keyboard (High)

**Where:** 12 pages at 375 px, including:

- seller dashboard and sales;
- admin orders, payouts, tickets, reviews, webhooks, coupons, gift cards, gateway and audit.

**Root cause:**

- Wide tables scroll sideways inside a `.table-wrap` box, so the page itself does not overflow.
- The box could not take focus, so a keyboard user (or a phone user with a keyboard, or anyone at 400 % zoom) could not reach the cut-off columns.
- This is WCAG 2.1.1 (Keyboard), level A. axe rule `scrollable-region-focusable`: 13 page views in each colour scheme at 375 px.

**Why it was missed:** the existing accessibility scan ran only at 1280 px, where the tables fit.

**Fix:**

- All 43 table boxes carry `tabindex="0"`, so they work without scripts.
- `enhance.js` keeps them in the tab order only while they actually scroll. Then it makes them a region named after the table caption or the nearest heading.
- They have a visible focus outline.

**Test:**

- the `mobile` project of `a11y.spec.js` (375 px, touch, WCAG 2.2 AA);
- `UxAuditTest::test_ux01_table_boxes_can_take_keyboard_focus`.

**Before / after:** 26 axe violations, then 0, over 584 page views.

### UX-02: Irreversible actions run on a single click (High)

**Where:**

| Role | Actions |
|---|---|
| Buyer | Cancel order; withdraw dispute |
| Staff | Cancel order; delete category; delete announcement; reset email template (the edited text is lost); delete failed job; remove a seller's review reply |
| Seller | Stop delivering a file; remove product image |

**Root cause:** each was a plain one-button form. A mis-tap on a phone or a wrong row was enough.

**Fix:** a new `x-confirm` Blade component.

- The first button only opens a panel that states the consequence and that it cannot be undone.
- A second, clearly labelled button ("Yes, cancel order") runs the action.
- It works without JavaScript (`<details>`), with the keyboard, and screen readers announce expanded and collapsed.
- Closing the panel cancels: nothing is sent.

**Where no confirmation was added:**

- Actions that already require a typed reason (reject, disable), since the reason is the deliberate step.
- Reversible ones (hide, pause, deactivate): no dialog for harmless actions.

**Test:**

- `ux.spec.js` "an irreversible action asks first and can be called off" (JavaScript off; it failed before: the category was deleted on the first click);
- `UxAuditTest::test_ux02_*`.

### UX-03: A session that ends is not shown (High)

**Root cause:** when the session ended (expiry, sign-out in another tab, revocation by staff), the live-update poll got a 401 and stopped silently.

- The page looked normal.
- The next submit hit an expired form token (HTTP 419), and the typed input was gone.
- The 419 page advised "reload the page and submit again", which also loses the input.

**Fix:**

- **On the page:** it now says "You are signed out. Sign in again in another tab to keep what you typed here.", with a link that opens the sign-in in a new tab. It keeps checking.
- **After signing in again:** the forms on the page get the new session's token, and the page says "You are signed in again. You can send the form now."
- **The 419 page:** it explains how to keep the input (go back, copy, sign in in a new tab, reload).

Input is deliberately not carried over automatically after a 419. A forged cross-site request could otherwise pre-fill a victim's form.

**Test:**

- `ux.spec.js` "when the session ends the page says so, keeps what was typed, and the form works after signing in again" (the ticket is created with the text typed before the session ended);
- `UxAuditTest::test_ux03_*`.

### UX-04: Seller pages stay stale after staff decisions (Medium)

**Root cause:** these pages had no live version:

- the seller's product list and edit page;
- the seller application page, whose confirmation message promises "you will see the decision on this page".

After an approval the seller got a notification toast, while the list on screen still said "Pending review" until a manual reload.

**Fix:**

- `LivePages` versions for `seller.products.index`, `seller.products.edit` and `seller.apply`.
- They use the same access rules as the pages: only the seller's own products, and nothing for anyone else.

**Test:**

- `ux.spec.js` "staff approval reaches the seller's product list without a reload" (failed before: still "Pending review" after 25 s);
- `UxAuditTest::test_ux04_*`, including that another seller gets no version.

### UX-05: Search suggestions are not keyboard- or screen-reader-friendly (Medium)

**Root cause:** the header search showed a list of suggestion links.

- The arrow keys did nothing.
- Escape closed the list but left the focus wherever it was.
- Nothing announced that suggestions had appeared.

**Fix:**

- Down arrow moves into the list; up and down arrows move through it and back to the field.
- Escape closes the list and returns to the field.
- A polite status announces "3 suggestions. Use the down arrow key to choose one."

**Why not `aria-expanded` on the field:** it is not allowed on a search field. The list stays a list of links, which also works by Tab.

**Test:** `ux.spec.js` "search suggestions can be used with the keyboard".

### UX-06: Second submits (Medium)

**Root cause:** only the eight payment-related forms (`data-once`) ignored a second submit.

**Observed:** a second submit 100 ms after the first sent a second POST (test with the first response held back).

**Not reproduced:** a duplicate record from a real double click. Three Chromium attempts against the staff balance and gift-card forms, at 0, 150 and 400 ms latency, created one record each. Chromium cancels the first navigation before it leaves the browser.

**Fix:** `enhance.js` now drops a second submit of any POST form while the first is on its way (10 s, or until the page changes).

- Download forms are excluded.
- The busy indicator stays as before.

**Test:** `ux.spec.js` "a form sent twice in quick succession is sent once" (2 POSTs before, 1 after).

**Server side:** see UX-18.

### UX-22: No warning before an idle session ends (Medium)

**Root cause:** the server ends a session after 120 minutes without a request (`SESSION_LIFETIME`). Nothing warned beforehand, which WCAG 2.2.1 (Timing Adjustable, level A) asks for unless the limit is longer than 20 hours.

**Already mitigated:** while someone uses the page, the live polling keeps the session alive. Polling pauses after 20 idle minutes. After expiry, UX-03 now keeps the typed input.

**Fix:**

- Two minutes before the idle limit, the page shows "You will be signed out in 2 minutes because nothing happened for a while.", with a "Stay signed in" button.
- The button makes a request that renews the session.
- The limit comes from the server (`data-session-lifetime`).

**Without JavaScript:** there is no warning. The expired-form page explains how to keep the input (UX-03).

**Test:** `ux.spec.js` "two minutes before an idle session ends, the page offers to stay signed in". It uses Playwright's clock to pass 118 minutes, clicks the button and checks that the request succeeds and the warning goes.

### UX-07: Focus after a failed submission (Low)

**Root cause:** after a failed submission the page reloads with an error summary (`role="alert"`) at the top, but the focus started at the top of the document. Keyboard users had to find the problems themselves.

**Fix:** the error summary takes the focus, so screen readers read it and Tab continues from there. The fields keep `aria-invalid` and `aria-describedby` linking to their messages.

**Test:** `ux.spec.js` "the error summary takes the focus after a failed submission".

### UX-08: Catalogue on phones (Low)

**Root cause:** seven filter fields stacked in one column. At 375 px the first product started at 837 px, below the first screen. With results showing, filters could only be cleared by hand.

**Fix:**

- Filters in two columns on narrow screens, with the search field across.
- A "Clear filters" link next to the result count whenever a filter is active.

**After:** the first product starts at 651 px (-186 px; same measurement method on both full-page screenshots).

**Test:** `UxAuditTest::test_ux07_catalogue_offers_clear_filters_when_filtered`; crawl (no overflow at 320 px).

### UX-09: Touch targets (Low)

**Target size, WCAG 2.5.8 (AA):** met, with no axe `target-size` violation, before or after. Links in text and table cells use the spacing exception.

**44 px goal:** the project aims at 44 px for touch (2.5.5, AAA; `app.css` already applied it to buttons, fields and menus). It was missed by:

- the site name in the header;
- product card titles;
- links in tables and lists.

**Fix:**

- The whole product card is now the link (`::after` overlay); the title stays the link's accessible name.
- The site name is 44 px high on touch screens.

**Counted on touch viewports (320, 375 and 768 px):**

- below 44 px: 438 -> 207 instances;
- the site name is gone from the list.

The crawl measures the link element itself, so the card overlay is not counted (`ux.spec.js` taps the card body).

**Remaining:** links in table cells and notification lists. Left as is: they meet AA, and enlarging them would make the dense admin tables longer.

### UX-10: Phone in landscape (Low)

**Root cause:** at 812 x 375 the header (two rows of links) and the admin section menu (four rows) filled the entire first screen. The section menu only scrolled sideways below 768 px width.

**Fix:** section menus also become one sideways row on screens up to 30 rem (480 px) high.

**Test:** crawl at 812 x 375: no overflow; screenshots checked.

### UX-11: Fact lists in narrow cards (Low)

**Root cause:** `.facts` used `max-content 1fr`, so long labels or values (for example the Shkeeper URL on `/admin/gateway`) pushed the list past its card. This happened at widths where the sidebar column is narrow (769 to about 1000 px).

**Fix:** `minmax(0, max-content) minmax(0, 1fr)`; values already wrap anywhere.

**Test:** crawl at 812 px: 0 elements past the edge.

### UX-12: Unsaved text (Low)

**Root cause:** leaving or closing a page with a half-written ticket, dispute message or review lost it silently.

**Fix:**

- If a text area in a POST form has text, the browser asks before leaving, with its own `beforeunload` dialog.
- Not for ordinary fields, so short forms do not nag.

**Test:** `ux.spec.js` "leaving a page with a half-written message asks first".

### UX-13: Stray space (Low)

**Root cause:** the status badge component ends with a line break, so "Status: Paid ." showed a space before the full stop.

**Fix:** the order page uses a middle dot separator.

**Test:** `UxAuditTest::test_ux08_order_status_sentence_has_no_stray_space`.

### UX-14: Regression introduced and caught during the audit (Low)

**Root cause:** the first version of the UX-08 grid used the shared `.filters` class. It squeezed the date filters of the staff order and audit lists at 320 px: 10 px of page overflow.

**Caught:** the after-crawl caught it before the change was released.

**Fix:** the grid is limited to `.catalogue-filters`.

### UX-23: A rate-limited poll looked like a lost connection (Low)

**Root cause:** `/live` allows 30 requests per minute per user. Each visible tab polls 6 times a minute, so with more than five visible windows (a staff member with several monitors) the server answered 429. The script treated 429 like a network error and showed "Connection lost. Trying again..." after two of them. Nothing was wrong.

**Fix:** a 429 makes the tab wait 60 s before the next poll, without any message.

**Test:** `ux.spec.js` "a rate-limited poll slows down without claiming the connection is lost". Before: 2 polls in 40 s, on the way to the message; after: 1.

### UX-24: Wrong scope in the sound setting's label (Low)

**Root cause:** the notification sound setting sat under the heading "In this browser", but it is stored on the account (`users.notification_sound`). It therefore applies on every device.

**Fix:** the heading reads "While a shop page is open (on all your devices)".

**Test:** wording only; the setting itself is covered by `live.spec.js` (switching the sound off).

### UX-15: Header navigation after a role change (Low)

**Root cause:** live updates refreshed the page content, not the header. When staff approved a seller application, the applicant's page showed the decision at once (UX-04), but the "Seller" link in the header appeared only on the next page load. The same held when staff changed a role, also for the staff section menu.

**Fix:**

- `/live` now also returns the user's role, which decides the menus (`data-live-nav` on the page).
- When it changes, `enhance.js` fetches the page once and replaces the header menu and the section menu, or adds or removes the section menu.
- Forms are not inside the menus, so typed input stays. The page content follows through its own version as before.

**Test:**

- `ux.spec.js` "a role change shows up in the menus of the signed-in user without a reload": staff make the signed-in customer a seller and back. The "Seller" link appears and goes, in the same document, while text typed in the header search stays.
- `UxAuditTest::test_ux15_*`.

### UX-16: Times in UTC (Low, accepted)

**What happens:** all dates and times are shown in UTC and labelled "UTC". The order page, for example, says "Placed 2026-10-12 20:27 UTC".

**Assessment:**

- Consistent and unambiguous.
- For buyers far from UTC, the delivery windows and expiry times need converting in their heads.

**Decision (2026-10-13):** the owner decided to keep UTC only. No change.

### UX-17: Optional fields (Low)

**Root cause:** required fields carry `required` (announced by screen readers) and most optional ones said "Optional" or "Leave empty for ..." in a hint. Some did not, for example the category description, the gift-card expiry and the announcement dates.

**Fix:**

- The `field`, `select` and `textarea` components take an `optional` attribute, which adds "(optional)" to the label in muted text.
- 23 fields in staff and seller forms are marked. Each one was checked to be `nullable` in its controller's validation.
- Not marked:
  - filter forms (GET), where every field is optional by nature;
  - fields that are required only in some cases and say so in their label, for example "Outgoing transaction reference (manual only)";
  - selects that always have a value.
- The coupon hint "Optional, in the coupon currency." became "In the coupon currency.".

**Test:** `UxAuditTest::test_ux17_optional_fields_say_so`. The optional description is marked; the required name is not.

### UX-18: Server-side duplicate protection for two staff money forms (Low)

**Root cause:** staff balance adjustments and gift-card creation had no idempotency key, unlike checkout, payment, payouts and refunds. If a browser sent the form twice, two credits or two gift cards would be made. For a gift card, the first card's code would then never be shown, because the browser shows only the last response.

This was not reproduced in Chromium (see UX-06).

**Fix:** a new `submit.once` middleware and an `x-once` form component.

- **The key:** each form carries a one-time key.
- **A repeated key:**
  - It is not processed again.
  - It answers with the first request's success message, so a gift card's code is shown again rather than lost.
  - That message is kept encrypted (`Crypt`), for the same user only, for 10 minutes. The key itself is remembered for 24 hours.
- **Failures:** a submission that fails (validation error, refused amount) releases the key, so it can be sent again.
- **Missing key:** a form without a key, such as a page cached from before this change, is refused with "This form is out of date. Reload the page and send it again."
- **Order of checks:** the middleware runs after the permission check and after the recent-password check.

**Tests:**

- `UxAuditTest::test_ux18_*` (3 tests):
  - a resent balance adjustment is applied once and answers with the same message;
  - a resent gift-card form makes one card and shows the same code again, and the kept message is not stored in plain text;
  - a failed submission does not use up the key.
- The existing balance tests in `AdminFeaturesTest` and `SecurityAuditTest` now send a key, so the self-dealing check is still the one being tested.

### UX-25: Typing in the header search stopped live updates (Medium)

**Found by:** the UX-15 browser test, during this round.

**Root cause:** in `enhance.js` the search suggestions' debounce timer and the live poll's timer were both declared as `var timer` in the same function, so they were one variable. Typing in the header search cancelled the scheduled poll. That page then received no live updates until the tab was hidden and shown again, or after 20 idle minutes. This affected any signed-in page, for example a staff member searching from the dashboard. The code had this defect before the audit.

**Fix:** the search uses its own `searchTimer`.

**Test:** `ux.spec.js` "typing in the header search does not stop live updates". With the old file no poll followed within 20 s; with the fix it does.

### UX-19, UX-20: Decisions (Info)

**Colour scheme (UX-19):**

- Stored in a cookie for 400 days, per browser, not per account.
- Set in the footer.
- It works without JavaScript and without a flash of the wrong theme (the server renders the attribute).
- Other open tabs switch on their next page load.

**Two search fields (UX-20):** see the workflow inventory.

### UX-21: What could not be tested (Info)

**Screen readers:** none (NVDA, JAWS, VoiceOver, TalkBack) is available in this environment. What was checked instead:

- names, roles and states;
- live regions;
- focus order and focus moves;
- the accessibility tree through Playwright's role-based locators.

These are proxies, not a screen-reader test.

**Real users:** usability sessions and product analytics were not available.

**Browsers:** only the Chromium engine. Safari (iOS) and Firefox were not run.
