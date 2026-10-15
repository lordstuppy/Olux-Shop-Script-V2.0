# Shkeeper Contract Test (public demo)

Does the shop's Shkeeper client match the real Shkeeper? Tested on 2026-10-15 in two ways:

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
| Crypto list | PASS (BNB, BTC, ETH, LTC, XMR, XRP, tokens) |
| Invoice creation (`payment_request`): id, address, crypto amount, rate | PASS |
| Same order id again returns the same invoice | PASS. This is the retry after a lost answer (FF-02). Shkeeper reuses an invoice when the external id, callback URL and fiat currency match. |
| EUR invoices | PASS |
| Invoice status (`/invoices/{id}`): status, fiat, amount, balance | PASS; an unknown invoice reads as "none" |
| Wrong API key, unknown crypto | PASS: refused with a message (HTTP 200 with `status: error`, which the client handles) |
| Callback signature | Matches the shop: HMAC-SHA256 over `timestamp.body`, headers `X-Shkeeper-Timestamp` and `X-Shkeeper-Signature`, 300 s tolerance |
| Statuses | PASS. Invoices: UNPAID, PARTIAL, PAID, OVERPAID; payouts: IN_PROGRESS, SUCCESS, FAIL. |

### Mismatches

| # | Finding | Effect in the shop | Severity |
|---|---|---|---|
| SK-1 | **Quote field name.** The quote endpoint answers `amount_crypto`; the shop reads `crypto_amount`, which Shkeeper's own README documents. Confirmed live. | Every automatic payout and crypto refund through Shkeeper fails: both start with a quote. Staff see "Shkeeper did not accept ..." and have to pay manually. Buyer payments are not affected. | High |
| SK-2 | **"Unconfirmed" callback.** When a transaction first appears, Shkeeper sends `{"status": "unconfirmed", "txid", "amount", ...}` with no `fiat` and no `balance_fiat`. | The shop treats it as a payment in the wrong currency: it marks the payment "rejected" and sends staff a false "payment needs a decision" alert, for every crypto payment. The confirmed callback that follows still pays the order. | Medium |
| SK-3 | **Unknown payout.** The payout status answer for an unknown payout is HTTP 404 `{"error": "Payout not found"}`, without `status: error`. Confirmed live. | The shop sees an error instead of "Shkeeper never received it". A payout whose request got no answer stays "processing" for good, instead of becoming "failed" after 30 minutes, and repeated errors stop the status poll early. | Medium |
| SK-4 | **"Paid" from 95 %.** By default Shkeeper marks an invoice PAID from 95 % of the amount (wallet setting "Mark invoice as paid if amount paid is greater or equal to"). | The shop refuses anything below 100 %. The buyer sees "paid" at Shkeeper while the order stays open and staff get an alert. | Medium (configuration) |
| SK-5 | **2 % fee.** By default Shkeeper adds a 2 % fee to the crypto amount (rate setting "Fee calculation"). The callback's `balance_fiat` includes the fee. | The shop credits the fee back to the buyer's balance as an "overpayment" (probe: 25.50 paid for 25.00, so 0.50 credited). | Medium (configuration) |
| SK-6 | **Payout callbacks.** Shkeeper sends these only when the server sets `ENABLE_PAYOUT_CALLBACK`, and only for SUCCESS. Failures are never called back. | Failed payouts are noticed only by the shop's status poll, which SK-3 breaks for one case. | Low (configuration) |

## Required Shkeeper configuration for this shop

This applies until SK-4 and SK-5 are handled in code.

- **"Mark invoice as paid if amount paid is greater or equal to":** 100 % on every wallet.
- **"Fee calculation":** "No fee" for every currency the shop uses. Alternatively the shop has to subtract the fee (proposed below).
- **API key:** the same key on every wallet (the demo does this). `SHKEEPER_API_KEY` and `SHKEEPER_WEBHOOK_SECRET` must both be that key, because Shkeeper signs callbacks with the wallet's key.
- **Server:** `ENABLE_PAYOUT_CALLBACK=1` on the Shkeeper server, if payouts go through Shkeeper.

## Proposed fixes

Not implemented; this was a test only.

- **SK-1:** read `amount_crypto` and fall back to `crypto_amount`. Update the mock and its fixtures to the real format.
- **SK-2:** ignore "unconfirmed" callbacks with a 202 (optionally show "payment seen, waiting for confirmations").
- **SK-3:** treat HTTP 404 with an `error` field as "not found".
- **SK-4 and SK-5:** use `amount_fiat_without_fee` from the callback's transactions, or a shop setting for the tolerance, so the shop agrees with Shkeeper's decision. The alternative is to keep the required configuration above.
- **Mock:** update the Shkeeper mock and simulation to send unconfirmed callbacks, fees and the real field names. The scenarios would then have caught SK-1 to SK-5.
