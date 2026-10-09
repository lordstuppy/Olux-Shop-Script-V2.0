# Currency and Conversion Policy

## Storage

- Every amount is an integer number of minor units (cents) with an explicit
  ISO 4217 currency next to it. No float or decimal money column exists.
- Supported currencies and their minor-unit digits are configured in
  `config/shop.php` (`USD` and `EUR` by default; Shkeeper always accepts both
  as invoice fiat).
- Arithmetic that can exceed 64-bit integers or needs rounding (conversion,
  percentages, proportional splits) uses `brick/math`.

## Prices and orders

1. A product has one list price in one currency, set by the seller.
2. The buyer picks a cart currency (footer or cart page). Each unit price is
   converted into it with `CurrencyConverter`:
   - **Rates are explicit per direction.** `USD -> EUR` and `EUR -> USD` are
     separate rows in `exchange_rates`, maintained by an admin at
     `/admin/exchange-rates`. An inverse rate is never inferred.
   - **No rate, no sale.** If no rate exists for a direction, the product
     cannot be bought in that currency. The cart says so and suggests
     switching currency. Nothing is guessed.
   - **Rounding:** the converted unit price is rounded half-up to the target
     minor unit. The line total is unit price times quantity, so a line never
     carries a fractional cent.
3. The order stores, per item, the list price, the list currency and the rate
   used (`order_items.fx_rate`). Changing a rate later never changes an
   existing order, its invoice or its refunds.
4. An order has exactly one currency. The Shkeeper invoice is created in that
   fiat currency. Shkeeper converts to crypto at its own rate, which the buyer
   sees on the payment page.

## Payments received

- A Shkeeper notification is accepted only if its `fiat` equals the order
  currency. Its `balance_fiat` is parsed from the decimal string and
  **truncated** to minor units, so a received amount is never overstated.
- The order is marked paid only if the amount received is at least the order
  total. Otherwise the buyer sees, for example: "Payment amount mismatch.
  Expected 25.00 EUR, received 24.90 EUR. Order has not been marked as paid."
- Overpayments are recorded in the stored callback payload. They are resolved
  manually through a ticket and a balance credit; nothing is credited
  automatically.

## Balances, gift cards and refunds

- A user balance holds one currency.
- A credit in another currency (gift card, refund, late payment) is accepted
  only while the balance is zero; the balance then switches currency.
  Otherwise the credit is refused with an explanation, and staff handle it
  manually.
- A balance pays only for orders in the same currency.
- Refunds are in the order currency. A refund to balance follows the same
  rule as other credits.

## Coupons and commission

- Percent coupons are stored in basis points, and the discount rounds
  half-up.
- Fixed coupons carry a currency and apply only to orders in that currency.
- The order discount is split across lines in proportion to line totals,
  using the largest-remainder method, so the parts always add up to the
  discount.
- Seller commission is a per-line percentage (basis points) of the
  discounted line amount, rounded half-up. Seller earnings and payouts are
  kept per currency, and the ledger is never converted.
