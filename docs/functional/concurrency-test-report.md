# Concurrency Test Report

Race conditions, contention and the invariants that must survive them.

## How races were made to happen

Waiting for a race to occur by luck proves little, so competing operations were forced to overlap.

| Technique | Where | What it guarantees |
|---|---|---|
| **Barrier.** All competing requests wait on a `threading.Barrier` and are released together, each from its own browser session (cookies) and source address. | `tests/simulation/phases/common.py` `parallel()`; phases 6, 9 and 12 | The requests reach PHP-FPM within milliseconds of each other, on the production image with PostgreSQL 15. |
| **Held lock.** A `psql` transaction holds the row the operation needs (the order or the buyer) while requests or callbacks arrive. | phases 2, 8 and 12 (FR-01, FR-02) | The operation is inside PHP when the fault hits: a dropped connection, a PHP-FPM restart, a gateway timeout. |
| **Interleaving replayed step by step.** The test performs one actor's step, then the other's, in the order that breaks the invariant. | `tests/Feature/FunctionalGateTest.php` | A deterministic regression test for each race that was fixed. |
| **Load contention.** k6 virtual users, each from its own loopback address, on one limited product and one set of orders. | `tests/functional/load/profiles.js`, profile `contention` | 40 buyers for 10 units and 120 parallel callbacks for 5 orders, under load. |

Every simulation scenario then checks the database, not just the pages: the invariants of [transaction-integrity-report.md](transaction-integrity-report.md#invariants), no 5xx, and no undeclared error in the app log.

## Scenarios and results

The results are from the final runs on the candidate ([functional-test-summary.md](functional-test-summary.md#final-runs)). The scenario names link the tables to [scenario-matrix.md](scenario-matrix.md).

| Race | Scenario | Competing operations | Invariant checked |
|---|---|---|---|
| Last unit | P1 "Concurrent purchase of the last unit" | 2 buyers, stock 1 | One order; stock 0; the loser is told "just sold out" |
| Last units | P12 FC-08 | 8 buyers, stock 3 | Exactly 3 orders; stock 0; 5 refusals; 3 distinct keys delivered |
| Last units under load | load `contention` | 40 buyers, stock 10, balance checkout | Exactly 10 sold; stock 0; the rest refused; no 5xx |
| Double-click on checkout | P6 "Double-click on checkout and pay" | 3 submits of one form | 1 order; 1 debit |
| One balance, two devices | P12 FC-01 | 2 sessions of one buyer, 2 orders, balance for 1 | 1 paid order; no leftover order; balance = 15.00 - total |
| Staff debit against checkout | P12 FC-02 | Finance debit and buyer checkout | Balance >= 0 and equal to 20.00 minus what succeeded |
| Same email registered | P12 FC-03 | 5 sign-ups | 1 account; others get a field error |
| Duplicate callbacks | P12 FC-04, load `contention` | 10 identical callbacks; 120 for 5 orders | One confirmed charge, one ledger entry per line, one paid email |
| Payment against expiry | P12 FC-05 | Expiry command and PAID callback, 4 orders | Paid, or expired with the money credited in full; never lost, never twice |
| Two staff on one payout | P12 FC-06 | Approve and reject; then mark paid twice | One decision (FD-12); one ledger entry |
| Refund against dispute resolution | P12 FC-07 | Full refund from the order page and dispute refund | Refunded once; balance up once; dispute resolved (FD-13) |
| Two refunds at once | P9 "Concurrent money operations" | Finance and manager, 3/4 of the total each | Exactly one succeeds; refunded <= total |
| Last coupon use | P9 | 2 buyers, coupon with 1 use | Used once |
| Payout request double-click | P9 | 3 submits | 1 payout |
| Payout requests from two devices | P12 FC-11 | 2 x 0.7 of the available amount | At most the available amount requested; ledger >= 0 |
| Manual delivery twice | P9 | 2 submits | Delivered once; 1 email |
| Gift card redeemed twice | P9 "Gift cards" | 2 buyers, 1 card | Credited once |
| Review, dispute, wishlist double submit | P12 FC-09 | 3 submits each | One row each |
| Downloads at the limit | P12 FC-10 | 5 downloads, 1 left | 1 served; counter at the limit |
| Suspension during checkout | P12 FC-12 | Manager suspends, buyer pays | Completed before or refused; nothing half done; session ended |
| Stale moderation | P12 FS-02; FD-07 test | Approve, then a reject from the old page | Refused; product stays active |
| Two database transactions on one checkout form | PHPUnit `OrderServiceTest`, `PurchaseFlowTest` | Same idempotency key | Unique key; the first order is returned |
| Gift card, coupon, webhook replays | PHPUnit `ShopFeaturesTest`, `WebhookTest`, `WebhookRetryTest` | Replays | Once |

### Races replayed in PHPUnit (fixed defects)

These are replayed in `FunctionalGateTest` and listed in [test-defect-log.md](test-defect-log.md):

- **FD-01:** late payout success after a rejection.
- **FD-02:** expiry or cancel against money on a refused payment.
- **FD-03:** balance payment against partial crypto.
- **FD-04:** product form save against sales.
- **FD-05:** resent refund.
- **FD-06:** withdrawal against resolution.
- **FD-07:** moderation against a seller edit.
- **FD-08:** price change against checkout.
- **FD-10:** a second delivery job.
- **FD-12:** a payout decision against a colleague's.
- **FD-13:** a refund against an open dispute.

## Locking and uniqueness in the code

| Mechanism | Used for |
|---|---|
| `SELECT ... FOR UPDATE` in a stable order | The buyer row (serialises a buyer's checkouts and balance changes). Limited-stock products by id (no deadlocks). Order rows (payments, refunds, expiry, cancel). Order lines (delivery, downloads). Payouts, disputes, coupons, gift cards, licence keys (`SKIP LOCKED`). |
| Shared lock | Unlimited products during checkout, so buyers of one product do not queue |
| Conditional update (`WHERE status = ...`) | Pause and resume, mark for review (FD-07), staff product status |
| Cache lock (database store) | One staff resolution per dispute at a time; the buyer's withdrawal too (FD-06) |
| Optimistic check of the shown state | Product moderation `seen_status` and `version` (FD-07); payout decisions `seen_status` (FD-12); checkout `expected_total` (FD-08); seller stock `stock_seen` (FD-04) |
| One-time form key (`submit.once`) | Staff balance adjustments, gift cards, refunds, payout requests (FD-05) |
| Unique keys | Order idempotency key per buyer; one confirmed charge per order and provider; provider references; licence key fingerprints; review per product and buyer; wishlist entry; webhook event per provider key; invoice per order |
| CHECK constraints | No negative stock or balance; refunds within totals; totals consistent |

## Not verified

- **Several application instances racing each other (G-06):** all locks are in PostgreSQL and the cache store is the database, so the mechanisms above apply across instances. This was not executed.
- **Concurrent large exports and reports:** each export streams in keyset pages; one export of 200,000 orders ran alongside nothing else ([load-stress-test-report.md](load-stress-test-report.md#large-data)). Several at once were not run.
