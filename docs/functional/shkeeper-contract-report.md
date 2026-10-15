# Shkeeper Contract Test (public demo)

Does the shop's Shkeeper client match the real Shkeeper? Tested on 2026-10-15 in two ways. SK-1 to SK-3 below are fixed since; the rerun against the demo passes all checks.

1. **Live, against the public demo** (`https://demo.shkeeper.io`, the published demo login).
   - The shop's own `ShkeeperClient` ran through `tests/shkeeper/contract.php`.
   - It created unpaid invoices only. No money moved and the payout endpoint was never called.
   - Results: [evidence/shkeeper-demo-contract.json](evidence/shkeeper-demo-contract.json). The API key is not stored there.
2. **Against the Shkeeper source code** (github.com/vsys-host/shkeeper.io, commit `e6a4128`, 2026-08-31).
   - Callbacks and payout callbacks cannot reach the sandbox from the demo, so their exact format was read from the source.
   - That format was replayed against the shop in a throwaway PHPUnit probe.

**Not covered:** a real payment, real callbacks over the network, payouts and crypto refunds. These need an own Shkeeper instance with a funded wallet and a shop reachable from the internet.

## Results

### What matches

| Check | Result |
|---|---|
| Quote (`/quote`), as payouts and crypto refunds use it | PASS since the SK-1 fix |
| Unknown payout reads as "Shkeeper never received it" | PASS since the SK-3 fix |
| Crypto list | PASS (BNB, BTC, ETH, LTC, XMR, XRP, tokens) |
| Invoice creation (`payment_request`): id, address, crypto amount, rate | PASS |
| Same order id again returns the same invoice | PASS. This is the retry after a lost answer (FF-02). Shkeeper reuses an invoice when the external id, callback URL and fiat currency match. |
| EUR invoices | PASS |
| Invoice status (`/invoices/{id}`): status, fiat, amount, balance | PASS; an unknown invoice reads as "none" |
| Wrong API key, unknown crypto | PASS: refused with a message (HTTP 200 with `status: error`, which the client handles) |
| Callback signature | Matches the shop: HMAC-SHA256 over `timestamp.body`, headers `X-Shkeeper-Timestamp` and `X-Shkeeper-Signature`, 300 s tolerance |
| Statuses | PASS. Invoices: UNPAID, PARTIAL, PAID, OVERPAID; payouts: IN_PROGRESS, SUCCESS, FAIL. |

### Mismatches

The "Effect" column describes the shop before the fixes.

| # | Finding | Effect in the shop | Severity | Status |
|---|---|---|---|---|
| SK-1 | **Quote field name.** The quote endpoint answers `amount_crypto`; the shop reads `crypto_amount`, which Shkeeper's own README documents. Confirmed live. | Every automatic payout and crypto refund through Shkeeper fails: both start with a quote. Staff see "Shkeeper did not accept ..." and have to pay manually. Buyer payments are not affected. | High | Fixed |
| SK-2 | **"Unconfirmed" callback.** When a transaction first appears, Shkeeper sends `{"status": "unconfirmed", "txid", "amount", ...}` with no `fiat` and no `balance_fiat`. | The shop treats it as a payment in the wrong currency: it marks the payment "rejected" and sends staff a false "payment needs a decision" alert, for every crypto payment. The confirmed callback that follows still pays the order. | Medium | Fixed |
| SK-3 | **Unknown payout.** The payout status answer for an unknown payout is HTTP 404 `{"error": "Payout not found"}`, without `status: error`. Confirmed live. | The shop sees an error instead of "Shkeeper never received it". A payout whose request got no answer stays "processing" for good, instead of becoming "failed" after 30 minutes, and repeated errors stop the status poll early. | Medium | Fixed |
| SK-4 | **"Paid" from 95 %.** By default Shkeeper marks an invoice PAID from 95 % of the amount (wallet setting "Mark invoice as paid if amount paid is greater or equal to"). | The shop refuses anything below 100 %. The buyer sees "paid" at Shkeeper while the order stays open and staff get an alert. | Medium (configuration) | Open: configuration |
| SK-5 | **2 % fee.** By default Shkeeper adds a 2 % fee to the crypto amount (rate setting "Fee calculation"). The callback's `balance_fiat` includes the fee. | The shop credits the fee back to the buyer's balance as an "overpayment" (probe: 25.50 paid for 25.00, so 0.50 credited). | Medium (configuration) | Open: configuration |
| SK-6 | **Payout callbacks.** Shkeeper sends these only when the server sets `ENABLE_PAYOUT_CALLBACK`, and only for SUCCESS. Failures are never called back. | Failed payouts are noticed only by the shop's status poll, which SK-3 breaks for one case. | Low (configuration) | Open: configuration |

## Required Shkeeper configuration for this shop

This applies until SK-4 and SK-5 are handled in code.

- **"Mark invoice as paid if amount paid is greater or equal to":** 100 % on every wallet.
- **"Fee calculation":** "No fee" for every currency the shop uses. Alternatively the shop has to subtract the fee (proposed below).
- **API key:** the same key on every wallet (the demo does this). `SHKEEPER_API_KEY` and `SHKEEPER_WEBHOOK_SECRET` must both be that key, because Shkeeper signs callbacks with the wallet's key.
- **Server:** `ENABLE_PAYOUT_CALLBACK=1` on the Shkeeper server, if payouts go through Shkeeper.

## Fixes

Regression tests: `tests/Feature/ShkeeperContractTest.php`, 5 tests. Their fakes use the answers the demo and the Shkeeper source give. All 5 fail on the code before the fixes ([evidence/shkeeper-regression-before-fix.txt](evidence/shkeeper-regression-before-fix.txt)).

| # | Fix |
|---|---|
| SK-1 | `ShkeeperClient::quote` reads `amount_crypto` and falls back to `crypto_amount`, the name the README documents. |
| SK-2 | `PaymentService::handleShkeeperNotification` acknowledges an "unconfirmed" callback with 202 and does nothing else: no payment change, no audit entry, no staff alert. It is stored as an ignored webhook event. The invoice callback that follows decides. |
| SK-3 | A 404 counts as "Shkeeper does not know this payout" when its JSON body has an `error` text or `status: error`. A 404 page from a proxy or a wrong base URL still proves nothing, as before. |

**Test fakes:** the quote and payout-status fakes in the other tests now use the real answers. Each fake was changed in the answer's shape only; no assertion was changed.

### Mock

`docker/shkeeper-mock/server.php` now answers and calls back like the real Shkeeper, configured as required above:

- the quote answers `status`, `fiat`, `amount_fiat`, `crypto`, `amount_crypto` and `exchange_rate`;
- an unknown payout answers HTTP 404 `{"error": "Payout not found"}`;
- a wrong API key answers HTTP 200 `{"status": "error", "message": "Bad API key"}`;
- a payment first sends the "unconfirmed" callback for the new transaction, then the invoice callback;
- invoice callbacks carry all of Shkeeper's fields: per transaction `date`, `amount_crypto`, `amount_fiat_without_fee`, `fee_fiat`, `crypto` and the `trigger` flag on the latest one; `balance_crypto`, `fee_fixed`, `fee_policy`, `overpaid_fiat`;
- callback bodies are compact JSON with sorted keys;
- a payout callback is sent for SUCCESS only, signed and without the API key header. It includes `amount_fiat` and `currency_fiat`. A failed payout is only visible through the status poll, as with Shkeeper.

**Simulation changes:**

- Phase 2 "Successful payment" now also checks that the "unconfirmed" callback is answered 202, stored as ignored, and neither rejects the payment nor alerts staff.
- Phase 9 "Crypto refund through the gateway" no longer gets a failure callback. It runs the transfer status poll (`shop:reconcile-shkeeper-transfers`), as production does every 5 minutes.

The mock still has no fee and no 95 % threshold, because the shop requires that configuration (SK-4, SK-5).

## Still open

- **SK-4 and SK-5:** use `amount_fiat_without_fee` from the callback's transactions, or a shop setting for the tolerance, so the shop agrees with Shkeeper's decision. Until then the required configuration above applies.
- **SK-6:** configuration only (`ENABLE_PAYOUT_CALLBACK=1`). The status poll covers failed payouts.
- **Not covered by this test:** a real payment, real callbacks over the network, payouts and crypto refunds. These need an own Shkeeper instance with a funded wallet and a shop reachable from the internet.

## Rerun

- **PHPUnit:** 345 / 345 (4,301 assertions), including the 5 new regression tests.
- **Pint, PHPStan level 5, ASCII:** clean.
- **Contract test against the demo:** all 10 checks PASS ([evidence/shkeeper-demo-contract.json](evidence/shkeeper-demo-contract.json)). The quote and the unknown payout now read correctly.
- **Simulation phases 2, 5, 9 and 12 with the new mock:** running; the result is added here when it is in.
