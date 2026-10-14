# Transaction Integrity Report

Payments, orders, refunds, payouts, balances, stock and entitlements: what each critical operation must do, the invariants that must always hold, and what was verified.

**Scope:**

- The shop sells digital goods. Payment is in crypto through Shkeeper, or from the shop balance.
- There are no bookings and no recurring charges: subscriptions are prepaid periods.
- Crypto payments cannot be charged back. Chargebacks, recurring renewals and booking slots are therefore NOT APPLICABLE ([scenario-matrix.md](scenario-matrix.md), G-02 to G-04).

**Provider:** every gateway scenario runs against the Shkeeper mock (`docker/shkeeper-mock/server.php`). It signs callbacks like Shkeeper and injects failures: timeouts, 429, 500, 503, a reset in the middle of the answer, bad JSON, and a request processed whose answer is lost. Reconciliation against real Shkeeper sandbox records was not possible (G-01, BLOCKED).

## Invariants

These are checked after every simulation scenario and every load profile (`tests/simulation/simlib/shop.py`, `shop:reconcile-payouts`). Several are also enforced by the database, so a bug cannot persist a violation.

| # | Invariant | Database guard |
|---|---|---|
| I1 | A paid, delivered or refunded order is covered by a confirmed charge of at least its total. | - |
| I2 | Order total = subtotal - discount; line amounts add up to the subtotal. | `orders` CHECK (total = subtotal - discount, all >= 0) |
| I3 | Refunds never exceed the total, per order and per line; line refunds add up to the order refund. | CHECK `refunded_minor <= total_minor` on orders and order_items |
| I4 | Each sold line has exactly one sale ledger entry; unpaid orders have none. | - (guarded by the order row lock in `markPaid`) |
| I5 | Seller earnings equal the ledger per line; payout entries equal non-rejected payouts (`shop:reconcile-payouts`). | - |
| I6 | No negative stock or balance; balance history matches the balance. | CHECK `stock >= 0`, `balance_minor >= 0`, `balance_after_minor >= 0` |
| I7 | Licence keys go only to paid lines, at most one line per key. Delivered lines belong to paid orders. | unique `(product_id, key_fingerprint)` |
| I8 | At most one confirmed charge per order and provider; a provider reference is used once. | unique indexes `payments_one_charge_per_provider`, `payments_provider_reference_unique` |
| I9 | One order per checkout form (idempotency key). | unique `(buyer_id, idempotency_key)` |
| I10 | No orphan lines, payments or ledger entries. | foreign keys |
| I11 | Money that arrived is never lost: it pays the order, is credited to the balance (late or overpayment), or the order is kept open and staff are alerted (FD-02). | - |
| I12 | Money that left the shop is on the ledger, including a payout reported as sent after it was rejected (FD-01). | - |

## Critical operations

What each operation needs, changes and promises. "Lock" is the row lock that serialises competing requests.

| Operation | Actor and precondition | Input constraints | Database changes | External effects and notifications | Failure and recovery |
|---|---|---|---|---|---|
| Checkout, crypto | Verified, active buyer; cart items purchasable; fewer than 3 unpaid orders | Idempotency key; accepted terms; `expected_total` equals the computed total (FD-08); quantity 1-10; within stock | Lock on the buyer, the limited products (by id) and the coupon. Creates the order and lines, reserves stock and coupon use. Then a pending charge with the invoice. | Invoice at Shkeeper (`external_id` = order id). Order placed email. | Gateway error, timeout, 429 or 503: the order is kept, the buyer gets a retry message within 10 s, and the retry reuses the invoice. A lost answer: the invoice exists at Shkeeper, and the retry returns the same invoice (FF-02). |
| Checkout, balance | As above; balance in the cart currency covers the total | As above | One transaction: order, debit, confirmed charge, paid, ledger. All or nothing. | Paid and seller sale emails after commit; delivery job | Too little balance: no order, nothing reserved. A dropped connection: the server finishes or never ran, and resending gives the same order (FR-01). |
| Payment callback | Shkeeper, HMAC-signed, timestamp within 5 minutes, body up to 64 KB, from an allowed address when SHKEEPER_WEBHOOK_ALLOWED_IPS is set | `external_id` is an order uuid; scalar fields | Lock on the order, then the charge. Partial: amount received, never lowered. Paid with total covered: confirmed, order paid, ledger booked, overpayment credited. Wrong amount or currency: refused, order kept, staff alerted (FD-02). Order already expired or cancelled: late payment credited. | Delivery and invoice jobs after commit; paid email once (FD-10) | Duplicate, out-of-order or parallel callbacks: one charge. A callback while the order row is locked: Shkeeper resends. A lost callback: the status poll every 5 minutes. |
| Order expiry (every minute) | Scheduler | Pending, past `expires_at`, no incoming money | Lock on the order. Expired; stock and coupon released. | - | A payment at the same moment ends either paid, or expired with the money credited (FC-05). |
| Buyer cancel | Buyer owns it; pending; no incoming money | - | Lock on the order. Cancelled; reservations released. | - | Refused once money has arrived; staff decide (FD-02). |
| Pay pending order from balance | Buyer owns it; pending; no incoming crypto (FD-03) | - | As balance checkout | As balance checkout | Refused while crypto has partly arrived |
| Instant delivery job | Queue worker; order paid | - | Lock on each line. Keys taken with SKIP LOCKED; files listed; delivered. Completes the order. | Paid email once per order | 5 tries with backoff. A lost job is re-queued by `shop:recover-stuck-orders`. Running out of keys records `delivery.failed` for staff. |
| Manual delivery | Seller of the line, or staff; order paid or partly refunded; line not refunded in full (FD-09) | Text up to 10,000 characters | Lock on the line; delivered once | Delivered email | A second delivery is refused |
| Refund (balance or manual) | `orders.manage` (admin, manager, finance); recent password; one-time form key (FD-05) | Amount up to the refundable amount, which pending crypto refunds also reduce | Lock on the order, then the lines. A refund payment; line refunds in proportion; earnings adjusted; partly refunded or refunded. A disputed line refunded in full resolves its dispute (FD-13). | Refund email; balance credit | Parallel refunds: the sum never exceeds the total. A resent form: once. |
| Crypto refund | As refund, method Shkeeper | Destination address; crypto | Pending refund payment that reserves the amount; booked only on Shkeeper's success | Shkeeper transfer | Failure frees the amount for a retry. A late success after a failure alerts staff and books nothing. |
| Payout request | Approved seller; payout address not changed in the last 48 h; one-time form key | Amount between the minimum and the available amount (held, in-dispute and in-hold earnings excluded) | Lock on the seller. Payout requested; ledger debited at once. | - | Parallel or resent requests: the total never exceeds the available amount (FC-11). |
| Payout decision | `payouts.manage`; recent password; status the page showed (FD-12) | Reference or reason | Lock on the payout. Approve, mark paid (also directly from requested) or reject (ledger reversal). Rejected is final. | Status email | A stale page is refused. Parallel mark-paid: one ledger entry. |
| Payout through Shkeeper | As above; approved or failed | - | Claimed as processing before money moves; attempt number | Shkeeper payout; result by callback or status poll | No answer: stays processing, never sent again blindly (FF-03). Unknown to Shkeeper after 30 minutes: failed. A late success after a rejection is booked and alerted (FD-01). |
| Dispute | Buyer within 14 days of delivery; one per line | Reason, wanted outcome, text of at least 10 characters | Opens and holds the line earning. Staff refund, replace or reject under a per-dispute lock; the buyer can withdraw under the same lock (FD-06). | Emails to the parties; staff alerts | A missed seller deadline escalates once |
| Gift card redeem | Buyer; rate-limited | Code; card currency equals the wallet currency | Lock on the card, then the buyer; credited once | - | Parallel redemption: once (phase 9) |
| Staff balance adjustment | `balances.adjust`; recent password; one-time form key | Amount; reason | Lock on the buyer; never below zero | Shown in the wallet history | A debit racing a checkout never makes the balance negative (FC-02) |
| Download | Owner; signed link that has not expired | - | Lock on the line. Counter at most the limit (FC-10). | File stream | Expired or tampered links refused; staff can reset the counter |

## Results

The executed scenarios behind each statement are in [scenario-matrix.md](scenario-matrix.md) (category C16 and phase 12).

The final runs are summarised in [functional-test-summary.md](functional-test-summary.md#final-runs).
