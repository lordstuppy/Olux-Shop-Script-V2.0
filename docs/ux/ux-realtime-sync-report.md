# Real-Time Synchronisation Report

## Architecture and why

| Part | How it works |
|---|---|
| Source of truth | The server (PostgreSQL). Pages are rendered on the server; the browser keeps no data cache and makes no optimistic updates. |
| Own actions | Every change is a form submission that answers with the changed page and a flash message. The user always sees the server's result, never a guess. |
| Other people's changes | `enhance.js` polls `GET /live?path=...` every 10 s while the tab is visible and was used in the last 20 minutes. The answer holds the unread count, the balance, the newest notification, and a version of the current page. |
| Page version | `App\Support\LivePages`: a hash of a few indexed values per page, such as the newest change of the buyer's orders. 26 route names have one. Each entry checks the same access as the page itself, and pages the user may not see get no version. |
| Applying a change | When the version changes, the page HTML is fetched again and only `#live-content` is replaced, by moving the parsed nodes (no `innerHTML`). If the person is typing in that area, the page offers "Show it" instead. |
| Other tabs | `BroadcastChannel` passes each poll result (unread count, balance) to the browser's other tabs. Toasts appear once per notification across tabs (`localStorage`). |
| Without JavaScript | Every page shows current data when loaded. Nothing claims to be live. |

**Why polling and not WebSockets or SSE:**

- The shop runs on PHP-FPM behind nginx, with no long-lived connection infrastructure.
- The freshness the shop needs is "within seconds": payments, staff decisions, ticket replies. Nothing is collaborative editing.
- Polling the database also works across several app instances without a message bus.
- A WebSocket or SSE service would add a stateful component with its own authentication, authorisation and scaling for no user-visible gain.

## Freshness targets

| Situation | Target | Verified |
|---|---|---|
| Visible tab, used in the last 20 minutes | Change visible within one poll (10 s) plus one page fetch | Every live test passed within its 25 s window (at most two polls plus the page fetch). The exact delay was not recorded. |
| Tab hidden, then shown again | Immediately on return (a poll on `visibilitychange`) | Code review; `live.spec.js` covers the badge in a second tab |
| Idle more than 20 minutes | Paused (so an open tab does not keep the session alive); resumes on the first interaction | Code review; UX-22 test (fake clock) |
| Network errors | Retries after 20, 40, then 60 s; "Connection lost" after 2 failures, "Connection restored" when back | `live.spec.js` |
| Rate limit (more than 5 visible tabs of one user) | Waits 60 s, no message (UX-23) | `ux.spec.js` |
| Session ended | Says so at the next poll, keeps checking; renews form tokens after a sign-in elsewhere (UX-03) | `ux.spec.js` |
| Idle session about to end | Warning 2 minutes before, with "Stay signed in" (UX-22) | `ux.spec.js` |

## Pages that update by themselves

**Buyer:**

- order, pay and result pages;
- order list, wallet;
- ticket list and ticket;
- dispute list and dispute;
- notifications.

**Seller:**

- dashboard, sales, payouts, reviews;
- **product list and product edit page (new, UX-04)**;
- **the application page (new, UX-04)**.

**Staff:**

- dashboard;
- order list and order;
- disputes, payouts, tickets, products, seller applications and reviews.

**Every signed-in page:**

- the unread badge and the wallet balance in the header;
- the header menu and the section menu, when the user's role changes (UX-15).

**Not live:**

- **Public pages** (catalogue, product, cart): there is no polling for guests. Prices and stock are checked again at checkout.
- **Pure settings and configuration pages:** these change only through their own forms.

## Scenarios tested

| Scenario | Test | Result |
|---|---|---|
| External integration changes a record: Shkeeper confirms a payment, and the buyer's order page turns paid with a toast, a sound and a new badge, without a reload | `live.spec.js` | Pass |
| Staff change data a user sees: staff answer a ticket while the buyer is typing a reply. The draft stays, and "This page has new information" is offered | `live.spec.js` | Pass |
| Staff approve a product, and the seller's list changes from "Pending review" to "Active" in place | `ux.spec.js` | Pass (failed before: UX-04) |
| A user changes data staff see: a buyer's new order appears in the staff order list | `ux.spec.js` | Pass |
| Several tabs: marking all read in one tab hides the badge in the other | `live.spec.js` | Pass |
| Staff change a signed-in user's role: the menus follow in place, typed text stays | `ux.spec.js` | Pass (failed before: UX-15) |
| Typing in the header search: polling continues | `ux.spec.js` | Pass (failed before: UX-25, a timer shared by search and polling) |
| Connection lost and back: the message, then "Connection restored", with no stale state | `live.spec.js` | Pass |
| Session ended while typing: the warning, the typed text kept, sign-in in another tab, the form sent | `ux.spec.js` | Pass (failed before: UX-03) |
| Concurrent staff decisions: two staff members decide the same seller application from stale pages; the second is refused with an error message and nothing changes | `UxAuditTest::test_u12_*` | Pass |
| Concurrent buyers: two buyers take the last unit at the same moment, one gets it | Simulation P1 | Pass |
| A seller pauses a product while staff decide on it: the conditional update refuses with "changed status a moment ago. Reload the page and try again." | Code review (`Seller\ProductController`) | Not tested by a test |
| Duplicate and out-of-order payment callbacks | Simulation P2 (101 scenarios) | Pass |
| Authorisation: no version for other people's orders, tickets, products or staff pages; internal staff notes never change a customer's version | `NotificationCentreTest`, `UxAuditTest` | Pass |

## Correctness properties

| Property | How it holds |
|---|---|
| Duplicate events | There are no events to duplicate. The version is a hash of state, so seeing it twice changes nothing. Toasts appear once per notification ID. |
| Out-of-order responses | One poll at a time per tab (the next is scheduled when the previous answer arrives), and only one page refresh at a time. A refresh always fetches the current page, so an older response cannot overwrite newer content. |
| Missed updates during an outage | The version compares state, not a stream, so the first successful poll after an outage brings the page up to date. |
| Deleted or forbidden records | The refresh then gets a redirect or an error, and the page reloads, showing the server's answer: the 404 or 403 page, or the sign-in page. |
| Optimistic updates | None, so there is nothing to roll back. |
| Concurrent edits | Status changes lock the row and re-check it; the loser gets a message. Messages (tickets, disputes) are append-only. Product edits are last-write-wins: a product has one owner. |
| Full-page reloads | Only when the session ended or the page is gone. Normal updates swap the content in place. |
| Data exposure | `/live` answers only for the signed-in user, with `Cache-Control: no-store, private`. Versions are opaque hashes; the content itself comes from the normal, authorised page. |
| Load | Polling is rate-limited (30 per minute per user). The performance audit measured the live polls at 42 to 68 ms p50 on the full dataset (PERF-18, PERF-25). |

## Gaps

| Gap | Severity | Note |
|---|---|---|
| Guests see catalogue changes on reload only | Info | Checkout re-checks prices and stock and says what changed |
| Freshness on real networks and at scale | Not verified | Measured locally and in the sandbox load test. Capacity is a performance-audit item (R-02). |
