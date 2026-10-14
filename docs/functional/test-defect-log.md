# Test Defect Log

Defects confirmed by the final functional gate, with root cause, fix and regression evidence.

**Baseline:** `ab32013` (the UX audit's candidate).

**How the defects were found:**

- **FD-01 to FD-11:** an independent review of the money, stock and state code, focused on concurrency. Each candidate was confirmed by replaying its interleaving in a test before it was fixed.
- **FD-12 and FD-13:** the new barrier-synchronised simulation scenarios of phase 12 (run 1 on image `915e28e0f738`, built from `424330b`).

**Regression tests:** `tests/Feature/FunctionalGateTest.php`, 13 tests.

- Each replays the interleaving step by step, so the result is deterministic.
- 12 of them fail on the code before their fix ([evidence/regression-tests-before-fix.txt](evidence/regression-tests-before-fix.txt)).
- The 13th checks the escape hatch the FD-02 fix keeps for staff, which already worked before.
- Simulation phase 12 replays the races themselves against the production image.

## Summary

| ID | Severity | Area | Defect | Fixed in | Regression test | Retest |
|---|---|---|---|---|---|---|
| FD-01 | High | Payouts | A payout rejected after an unanswered transfer, then reported as sent by Shkeeper, was ignored. The seller kept the amount and could withdraw it again (paid twice), with no alert. | `424330b` | `test_fd01_...` | PASS; phase 12 FF-03 |
| FD-02 | High | Payments | Money on a refused "paid" report (wrong amount or currency) did not hold the order. The order expired or could be cancelled with the buyer's money stuck, and staff were not told. Every 5-minute poll also wrote a new audit entry. | `424330b` | `test_fd02_...` (2) | PASS |
| FD-03 | High | Payments | Paying from the balance was possible while crypto for the same order had already partly arrived, so the buyer paid twice. | `424330b` | `test_fd03_...` | PASS |
| FD-04 | High | Stock | Saving the seller product form wrote back the stock it was rendered with: sales made meanwhile were undone, so keys were oversold and paid orders failed delivery. More stock than unused licence keys, or unlimited stock on a key product, was accepted. | `424330b` | `test_fd04_...` | PASS |
| FD-05 | Medium | Refunds, payouts | A refund form or payout request sent twice (resend after a network error, two tabs) was processed twice. Only a client-side guard existed. | `424330b` | `test_fd05_...` | PASS; phase 9 concurrency |
| FD-06 | Medium | Disputes | A buyer could withdraw a dispute while staff were refunding it. The money moved, the case ended "withdrawn" and staff saw an error, inviting a second refund. | `424330b` | `test_fd06_...` | PASS |
| FD-07 | Medium | Moderation | An approval applied to whatever the product was at that moment, so a seller edit made while the moderator looked went live unreviewed. A stale reject could disable a product a colleague had just approved. A seller save could revive a product staff had just disabled. | `424330b` | `test_fd07_...` | PASS; phase 12 FS-02 |
| FD-08 | Medium | Checkout | A price or exchange-rate change after the checkout page was shown was charged without asking: the balance was debited at the new total. | `424330b` | `test_fd08_...` | PASS |
| FD-09 | Medium | Delivery | After one line of an order was refunded, the manual lines still owed could not be delivered and dropped off the seller dashboard. | `424330b` | `test_fd09_...` | PASS |
| FD-10 | Low | Email | When the queue lagged, the recovery command queued a second delivery job, and the buyer got the "order paid" email and entry twice. | `424330b` | `test_fd10_...` | PASS |
| FD-11 | Low | Invoices | A buyer opening the invoice while the invoice job ran could get a 500 (unique key). | `424330b` | none (see below) | Code review |
| FD-12 | Low | Payouts | Two staff deciding the same payout at once both took effect (approve and reject). A decision on an out-of-date page was applied, although the UX audit (U12) states stale decisions are refused. | `40594c9` | `test_fd12_...` | PASS; phase 12 FC-06 |
| FD-13 | Medium | Disputes | A full refund of a disputed line from the order page left the dispute open ("waiting for the seller"), with no fitting way for staff to close it. | `40594c9` | `test_fd13_...` | PASS; phase 12 FC-07 |

**By severity:** High 4, Medium 6, Low 3; 13 defects. There is no Critical defect. All are fixed and retested; none is open.

## Details

### FD-01: a rejected payout that was sent after all

**Reproduction:**

1. Send a payout through Shkeeper; the request gets no answer (outcome unknown, payout "processing").
2. After 30 minutes Shkeeper does not know the transfer, so the status poll marks it failed.
3. Staff reject it, and the amount returns to the seller's earnings.
4. Shkeeper then reports the transfer as sent.

**Root cause:** `ShkeeperPayoutService::payoutSucceeded` accepted a late success only from "processing" or "failed". For "rejected" it returned "ignored". The "possible double payment" alert checked only "paid".

**Fix:** a success for a rejected Shkeeper payout is booked as paid. `PayoutService::bookSentAfterRejection` takes the amount from the earnings again and audits `payout.paid_after_rejection`. Staff get an alert to recover any second payment.

**Invariant restored:** money that left the shop is on the ledger, and payout reconciliation matches.

### FD-02: money on a refused payment report

**Root cause:** `hasIncomingPayment` counted only "partial" and "confirmed" charges. A refused charge was treated as no money, so expiry and cancel released the order.

**Fix:**

- A refused charge counts as incoming money (`Order::hasIncomingPayment`), so the order is kept.
- Staff are alerted once per payment (7-day throttle) with the next steps.
- Repeated identical refusals from the status poll no longer write audit entries.
- Staff (not the buyer) can still cancel such an order once they have settled the money at the gateway.

**Behaviour kept:** the buyer paying the rest still completes the order, because the amount received is cumulative.

### FD-03: balance payment on top of a partial crypto payment

**Fix:** `PaymentService::payWithBalance` refuses with a message when the order has incoming crypto.

### FD-04: the product form overwrote stock

**Fix:**

- The seller save runs under a row lock on the current row.
- An unchanged stock field (`stock_seen` equals the submitted value) keeps the stock that sales and key uploads made of it.
- For products with licence keys, the stock can be at most the number of unused keys and cannot be unlimited.
- `markForReviewIfActive` is a conditional update, so a staff disable made a moment ago is kept.

### FD-05: refunds and payout requests processed twice

**Fix:** `submit.once` (the one-time form key from UX-18) is applied to `admin.orders.refund` and `seller.payouts.store`. A resent form shows the first result.

Tests that post these forms now send `_once`, like the browser does; no assertion was weakened.

### FD-06: withdrawal during a resolution

**Fix:** `DisputeService::withdraw` takes the same per-dispute lock as the staff decisions, so the buyer is told to wait a moment.

### FD-07: moderation on an out-of-date page

**Fix:** the moderation forms carry `seen_status` and `version`, a fingerprint of the reviewed text, price, terms, files and images (`Product::reviewVersion`).

- A decision on a page whose status changed is refused.
- So is an approval whose content changed.
- Bulk actions are unchanged.

**Query cost:** the list loads files and images once for all rows, so no queries are added per row.

### FD-08: changed total at checkout

**Fix:** the checkout form carries `expected_total`. Order creation compares it inside the locked transaction and refuses a different total with both amounts. Nothing is reserved or debited.

### FD-09: manual lines after a partial refund

**Fix:**

- `deliverManually` accepts partly refunded orders and refuses lines that were refunded in full.
- The seller dashboard lists such lines.

### FD-10: duplicate "order paid" email

**Fix:** `DeliverOrder` sends the email once per order (`Cache::add`, 30 days). Staff "Deliver again" sends it again on purpose (`resend: true`), as its message promises.

### FD-11: invoice creation race

**Fix:** `InvoiceService::issue` locks the order row; locking a not-yet-existing invoice row serialised nothing. A unique-key race is caught and the existing invoice is returned.

**Regression test:** no deterministic test is possible in PHPUnit. Both racers would need separate committed transactions, and the test wraps everything in one. The change was reviewed, and the simulation's invoice downloads pass.

### FD-12: payout decisions on an out-of-date page

**Fix:** approve, send, mark paid and reject carry the status the page showed. The check runs under the payout row lock (`PayoutService::transition`, `ShkeeperPayoutService::sendPayout`). A deliberate decision from a fresh page still works, for example rejecting an approved payout.

### FD-13: dispute left open after a full refund

**Fix:** a refund from the order page that refunds a disputed line in full resolves its open dispute as "refunded", with the amount and a message to both parties (`DisputeService::closeRefundedLine`). A dispute's own refund closes the dispute itself, as before.

## Review findings that are not defects

| Candidate | Why not a defect | Where it stands |
|---|---|---|
| Delivery and refund race, so a refunded order is also delivered | The outcome equals "delivered, then refunded", which the business rules allow: digital goods are refunded after delivery in phase 5. | No change |
| Refunds after a payout make seller earnings negative; pending crypto refunds are not held | The ledger is correct accounting: the seller owes the amount. Holding or clawing back is a business decision. | Residual risk R-03 in the summary, owner decision |
| A payment callback before the checkout transaction commits is ignored | Nothing is lost: `shop:reconcile-payments` pays the order within about 7 minutes. | Scenario G-11, NOT VERIFIED |
| Suspension during a request in flight | Phase 12 FC-12 ran it: either the order completed before the suspension or it was refused, and nothing was half done. | PASS |

## Test harness corrections

These were mistakes in the new tests, not in the shop. They are listed because a test result is only as good as its test.

| Scenario | Problem | Correction |
|---|---|---|
| FS-01, first version | Expected "mark paid" to be refused for a requested payout. The payout list offers exactly that, so the test had invented a rule. | Now tests the transition the rules do forbid: a rejected payout marked paid from a stale form. |
| FS-01 | Picked a seller without a matching product (`no product matching ...`). | Picks any seller that has one. |
| FR-01, FR-02, first version | Both passed, but the restart and the dropped connection happened after the requests had finished (all six answered 200), so they proved nothing. | The account rows are now held by a database lock, so the requests are inside PHP. FR-02 now cuts all six off (502) and FR-01 exercises both "server finished" and "never processed". |

## Rerun of affected scenarios

- **PHPUnit:** 340 / 340 after FD-13 (4,279 assertions).
- **Pint, PHPStan level 5, ASCII, translation catalogue:** clean.
- **Simulation phase 12:** 19 / 19 on image `c173f14e26d2` (built from `40594c9`), after the harness corrections.
- **Full simulation, browser suite, load profiles and security gate on the final candidate:** see [functional-test-summary.md](functional-test-summary.md#final-runs).
