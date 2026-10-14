# Failure Recovery Report

Controlled faults in the disposable simulation stack and what the shop did. The stack is the production image (PHP-FPM, nginx), with a TLS front end, queue worker, scheduler, PostgreSQL 15, Mailpit, a clamd stand-in and the Shkeeper mock.

Faults are injected through:

- the mock's failure modes (`docker/shkeeper-mock/server.php`);
- `docker kill` and restarts of single containers;
- database row locks held from `psql`;
- a filled tmpfs;
- connections dropped mid-request.

Statuses are from the final runs ([scenario-matrix.md](scenario-matrix.md)).

## Timeouts, retries and recovery semantics

| Dependency or job | Bound | Retry | Idempotent because |
|---|---|---|---|
| Shkeeper API (invoices, quotes, status) | 5 s connect, 10 s total | None inline; the buyer retries from the payment page; status poll every 5 minutes, which stops after 3 failures in a row | Shkeeper keeps one invoice per `external_id` (the order id) |
| Shkeeper payouts and crypto refunds | Same | Never blind: an unknown outcome stays "processing" until Shkeeper reports. Unknown to Shkeeper after 30 minutes: failed. | `external_id` per attempt; claimed before money moves |
| Webhook processing | - | Stored first. Transient failures retried after 30 s, 2 min, 10 min, 30 min and 2 h, then "dead" (on the dashboard). | Order row lock; payment status; unique charge index |
| Delivery job | Worker timeout 60 s (`retry_after` 90 s) | 5 tries (30 s, 2 min, 10 min, 30 min). Lost jobs are re-queued every minute (`shop:recover-stuck-orders`). | Delivered lines skipped under a row lock; paid email once per order (FD-10) |
| Invoice PDF job | Same | 3 tries, 60 s apart; re-queued if missing | Invoice per order (unique), creation serialised on the order (FD-11) |
| Emails and notifications | Same | 5 tries with backoff | - |
| File scan job | Same | 5 tries with backoff | Status per file |
| Queue worker | Restarts hourly (`--max-time=3600`) | - | - |
| PostgreSQL | 5 s connect timeout | Requests answer 503 with Retry-After while it is down; `/health` answers 503 | Transactions |

## Scenarios

| Fault | Scenario | Expected | Where |
|---|---|---|---|
| Gateway hangs on invoice creation | P2 "Gateway timeout on invoice creation" | Order kept; retry message within the client timeout; no charge; the retry pays once | sim |
| Gateway answers 429, 503, or resets mid-answer | P12 FF-01 | Same, for each mode; each failure in the gateway log | sim |
| Gateway processed the invoice, answer lost | P12 FF-02 | Retry reuses the invoice; one charge; reconciliation adds nothing | sim |
| Payout sent, answer lost | P12 FF-03 | Payout stays "processing" and cannot be sent again; the result books it once | sim |
| Payout rejected, later reported sent | FunctionalGateTest FD-01 | Booked as paid, earnings debited again, staff alerted | PHPUnit |
| Gateway killed | P8 "Kill the payment gateway" | Clear checkout message; health page shows it; retry after recovery pays | sim |
| Gateway down for an hour of checkouts | P10 "Staff alert when Shkeeper stops answering" | Health critical; one staff alert per hour, also with the worker stopped | sim |
| Callback while the order row is locked (slow database) | P2 "Gateway timeout during callback processing" | First callback times out; Shkeeper resends; paid once | sim |
| Duplicate, out-of-order, delayed callbacks | P2 (5 duplicates, out of order, 30 minutes late); P12 FC-04 (10 in parallel) | One charge, one ledger, one email | sim |
| Forged and malformed callbacks | P2, P6, P10 | 401 or 400, audited, one staff alert | sim |
| PostgreSQL killed during six checkouts | P8 "Kill the database mid-checkout" | Complete or 503; health 503; recovers by itself; nothing half-created | sim |
| Database starting, too many clients | OutageTest, ReviewFindingsTest | 503 with Retry-After, never 500 | PHPUnit |
| Queue worker stopped; another killed with `kill -9` inside a delivery | P8 "Kill the queue worker" | Job retried once, delivered once | sim |
| Payment committed, jobs lost before queueing | StuckOrderRecoveryTest; P8 lost-job step | Re-queued within minutes; delivered once | PHPUnit, sim |
| Mail server down | P8 "Mail server down"; P10 "A failed email"; P11 | Retried and delivered later; a failed email lands in failed jobs, alerts staff and can be retried from the health page; the notification list does not depend on mail | sim |
| Disk full during upload | P8 "Disk full during upload"; StorageFailureTest | Clear error, no partial file, no row; works after space is freed | sim, PHPUnit |
| Backup and restore | P8 "Restore a backup" | Checksums equal the backup; later changes gone | sim |
| PHP-FPM restarted while six checkouts are inside PHP | P12 FR-02 | Requests cut off visibly (502); nothing half-written; the same form resent gives exactly one paid order each | sim |
| Browser connection dropped during checkout | P12 FR-01 | Server finished (one order) or never ran; resending gives one order and one debit | sim |
| Expired session during a workflow | P3 "Sessions"; browser `ux.spec.js` session end and idle warning | Clear message; typed text kept; works after signing in again | sim, browser |
| Lost browser connectivity during live updates | browser `live.spec.js` | "Connection lost" shown and cleared; missed changes recovered | browser |
| Redeploy: app container recreated | SIMULATION.md bug 38 | nginx re-resolves the app (no lasting 502) | earlier certification |
| Upgrade migrations on a populated database | P10 "Upgrade" | No row lost; pages served | sim |

## Not covered

- **Cache outage:** the cache is the database cache store, so a cache outage is the database outage above. There is no separate cache service to fail.
- **Network partitions between containers other than the gateway:** not injected. Database and mail outages were injected by killing the containers.
- **Several app instances:** not available (G-06).
