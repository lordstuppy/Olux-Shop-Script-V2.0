# Data Consistency Report

Whether the browser, the HTTP answers, the database, background processing, the gateway mock, emails, the audit log and a second session agree after critical operations.

## Layers checked

| Layer | How it is checked |
|---|---|
| 1. Client | Simulation: the page the client lands on and its flash messages. Browser suite: the DOM in Chromium. |
| 2. HTTP | Status and redirect of every request, recorded with the shop's request id in `trace.jsonl`. Any 5xx fails a scenario unless it declares it (503 while the database is down, 502 while PHP-FPM restarts). |
| 3. Business logic | The outcome the scenario expects (paid once, refused, credited), read back from the database. |
| 4. Database | SQL on PostgreSQL after every scenario: the invariants of [transaction-integrity-report.md](transaction-integrity-report.md#invariants), plus the scenario's own queries. PostgreSQL also logs every data change (`log_statement=mod`). |
| 5. Transactions and events | Queue jobs drained or retried; webhook events stored with their outcome; failed jobs counted. A scenario fails on an unexpected failed job or error log entry. |
| 6. Gateway | Invoices, payments and payouts as the Shkeeper mock recorded them, for example one invoice per order after a lost answer (FF-02). The real Shkeeper sandbox was not available (G-01). |
| 7. Notifications and audit | Emails in Mailpit (one "paid" email per order, refund and dispute emails); entries in the notification centre; audit log entries (one decision per payout in FC-06). |
| 8. A second session | Another buyer, seller or staff member reads the result: the seller sees the sale and ledger, staff see the order and the alert, the buyer's other device sees the paid order, and live updates reach the other tab. |

## Inconsistencies looked for

| Inconsistency | Checked by | Result |
|---|---|---|
| The UI reports success but no record exists | Every scenario reads the record after the success message | None found |
| A record exists but the UI reported failure | FR-01 (dropped connection: the server finished, and the retry shows that order); FR-02 (a 502 during a restart never left a debit without an order); P8 database kill | None found |
| Payment succeeded but the order stays pending | P2 duplicate, out-of-order, delayed and parallel callbacks; P12 FC-05 (payment against expiry); load profile `load` (100 orders paid by callbacks, 20 checkouts paid through the gateway) | None; a "paid" report with the wrong amount now keeps the order open and alerts staff (FD-02) |
| A refund succeeded but the balance did not change | P5 refunds; P9 crypto refunds; P12 FC-07 (balance rose exactly by the refund) | None |
| A notification for an operation that rolled back | Seller sale and paid emails are sent after commit (`afterCommit`). Balance-checkout refusals leave no email (FC-01). | None |
| A failed request partly changed the database | P1 "Insufficient balance"; P8 database kill; FR-02 restart; FF-01 and FF-02 gateway failures (order kept, no charge) | None |
| A deleted or restricted record still reachable | P5 suspend (session ended at once); P9 hidden reviews disappear; P4 IDOR (15 requests answer 403); the live endpoint gives no version for pages a user may not see (P11) | None |
| Two competing requests leave conflicting final states | [concurrency-test-report.md](concurrency-test-report.md) | Fixed: two payout decisions both applied (FD-12); a refunded line with an open dispute (FD-13) |
| A dispute stays open after its line was refunded | P12 FC-07 | Fixed (FD-13) |
| The seller ledger disagrees with orders and payouts | `shop:reconcile-payouts` after every scenario and load profile | No difference |

## Multi-session consistency

| Situation | Scenario |
|---|---|
| Two devices of one buyer at once | P3 "Two devices at once"; P12 FC-01 (only one of two balance orders paid) |
| A staff change seen by the user without a reload | browser live.spec (payment arrives), ux.spec (staff approval reaches the seller; role change in the menus) |
| A user change seen by staff | browser ux.spec (a buyer's order appears on the staff list) |
| Several tabs: badge, toasts once, sound setting | browser live.spec |
| An expired or revoked session | browser ux.spec (session end); P5 suspend; P12 FC-12 |

The results of these checks are in the final runs ([functional-test-summary.md](functional-test-summary.md#final-runs)).
