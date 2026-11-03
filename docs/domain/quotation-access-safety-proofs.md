[← Quotation access fence](quotation-access-qa2-qa4.md)

# Quotation access: pre-activation safety proofs

Owner-directed continuation of [#77](https://github.com/kaleemnadeem16/erp/issues/77),
from develop `606fed69`, 3 November 2026. This records safety work, not activation.
Every quotation/document/delivery duty remains STAGED and the production UI gate stays off.

## Decisions and activation gate

The owner approved synthetic measurements on the local Docker host. **Production activation
is BLOCKED.** Before realistic measurement/sign-off, the owner must supply representative
company counts, a concurrent workload profile and latency budgets. These inputs and separate
hardware are unavailable; no production targets are inferred from the measurements below.

Keep the conservative all-company fence pending a separately proven replacement. A writer
acquires every company generation row exclusively, in ascending company ID order, **before**
family/revision locks, authority checks, receipts and business work. A protected read holds
its shared company fence through materialisation. Do not upgrade a protected read into a
write or acquire the fence after a family lock. Controllers must not wrap `QuotationOperation`
in another transaction: only an outermost operation can restart a deadlock victim safely.
A caller-owned transaction must roll back the entire operation on failure.

Bounded waits are technical failure limits, not accepted service latency budgets:

- `CompanyAccessFence` sets transaction-local `lock_timeout` to at most 1 second and
  `statement_timeout` to at most 30 seconds **before** its locking statements. A stricter
  caller setting is preserved. Commit/rollback restores the session settings.
- Migration `2026_11_10_000000_bound_company_access_barrier_waits.php` adds a fixed 1 second
  lock timeout to the raw security-writer trigger function too. The function-local setting
  is restored on return, including failure. This covers writers outside Laravel.
- Raw/external maintenance sessions must set `statement_timeout` before executing writes
  (30 seconds for this harness). Setting it *inside* a trigger would not bound the statement
  already executing, so the migration makes no such claim. Never treat raw SQL as a supported
  authorisation bypass. Protected application operations get the transaction-local bounds.
- A timeout aborts the transaction; it never bypasses the fence, returns cached privileged
  output, grants authority or resumes partially completed work. No automatic retry of lock
  timeouts is introduced. Deadlock/serialisation retries retain their existing bounded policy.

Rejected: increasing waits to disguise contention; reducing locks to the current company
without proving shared-role/security-writer ordering; treating the existence of a counter,
a benchmark result or a retry as activation approval.

PostgreSQL documents the [per-statement timeout semantics](https://www.postgresql.org/docs/16/runtime-config-client.html)
and [function-local configuration restoration](https://www.postgresql.org/docs/16/sql-createfunction.html);
these inform the distinction between the protected operation and a raw external statement.

## Repeatable synthetic measurement

Run only from this checkout, with a private agent identity:

```sh
ERP_AGENT=quotation-security docker/backend/measure-access-fence.sh \
  --companies=3 --writers=4 --readers=2 --rows=100 --iterations=20 \
  --hold-ms=5 --lock-timeout-ms=1000
```

The wrapper locks the shared stack and its own database, validates the private name, then
rebuilds only `erp_testing_<agent>` on `postgres-test`. It never runs against `erp` or the
application stack database. Do not use this destructive fixture rebuild on a database whose
contents you need. Company count, concurrent writers/readers, family rows **per fixture
company**, iterations, simulated transaction hold and session lock timeout are parameters.
The schema also has its ordinary baseline company; `--companies` counts added fixture companies.

Workers are independent OS processes and PDO connections with a common start barrier.
Writers take the all-company lock before a no-op family write; readers lock one company and
materialise family IDs; a separate worker updates the synthetic login timestamp. The JSON
reports SQLSTATE counts and p50/p95/p99/max lock-acquisition and total durations, in milliseconds.
Lock distributions include successful acquisitions only; failed waits are represented in
SQLSTATE counts and total durations. Login total time is reported, with no fence-lock metric.

Measured on the local Docker host, PostgreSQL 16.14, 3 November 2026:

| Parameters | Writer results | Reader results | Login results |
|---|---|---|---|
| 3 fixture companies, 100 families each, 4 writers, 2 readers, 20 iterations, 5 ms hold, 1000 ms wait | 80/80 succeeded; lock p95 52.151 ms, max 70.231 ms; total p95 58.720 ms | 40/40 succeeded; lock p95 18.892 ms; total p95 24.716 ms | 20/20 succeeded; total p95 6.722 ms |
| Same fixtures/workers/iterations, 20 ms hold, 5 ms wait | 4/80 succeeded, 76 SQLSTATE 55P03; total p95 12.285 ms | 12/40 succeeded, 28 SQLSTATE 55P03; total p95 25.269 ms | 20/20 succeeded; total p95 21.551 ms |

The short-wait run deliberately demonstrates refusal under contention; it does not prescribe
5 ms as an operating limit. The trigger still uses its 1 second function-local bound; this
trial's shorter wait applies to the explicit fence acquisition before the family write.

Limits: one host, synthetic data/holds, no representative concurrency profile, no production
latency budget, small sample and scheduling noise. Timings include query/client overhead.
This measures the lock graph, **not** full API, pricing, issuance, PDF rendering, disk or network
throughput. It cannot establish real-size safety, fairness, worst-case latency or starvation
freedom. Record new parameters and outputs when rerunning; do not present one run as a guarantee.

## Deadlocks and lock order

`CompanyAccessFenceConcurrencyTest` synchronises two real sessions holding shared locks before
both request an exclusive update. PostgreSQL returns 40P01 to one, the other commits, and exactly
one generation increment survives. This reproduces advisory A2; simulated exceptions alone did
not prove it. The paired exclusive-first test serialises both writers and commits both increments.

The ordering argument is limited to the supported writer path: all competing quotation writers
serialize at the same ascending exclusive fence **before** subject locks, so those writers cannot
form a company/family lock-order cycle with one another. Protected reads do not mutate fenced
tables. `QuotationAccessSurfaceCoverageTest` inventories all 16 quotation/document/delivery routes
and the internal family writers, with their Action, policy path, exact duty, projection and
behaviour suite. `CompanyAccessFenceTest` separately inventories database security writers.

This is not a claim that arbitrary raw SQL, an external integration, a caller holding prior row
locks or every future writer is deadlock-free. Such callers must obey the same order; PostgreSQL
aborts unsafe attempts, and bounds/retries fail closed. Production lock-order review remains a
sign-off requirement. A separate two-session test also reproduces A3: one transaction locks a family first,
while another holds the exclusive company fence and requests that family. A subsequent raw
security write by the first transaction produces a real 40P01 victim. The supported path
prevents this inversion by taking the fence before any family lock; arbitrary family-first
callers remain prohibited, not silently supported.

## Populated rollback and backout

All migrations at/after `2026_10_29_120000` have populated refusal proofs, distributed across
`QuotationDocumentApiTest` (issue, artefacts, access events, delivery), `QuotationControlSetTest`,
`QuotationLifecycleTest` and `QuotationAccessRollbackProofTest` (OWN scope variants, Tax source
facts, all three generation/barrier migrations, customer-decision receipts and the new timeout).
`AssertsPopulatedRollbackRefusal` requires real rows, an intentional RuntimeException rather than
a SQL failure, and retained trigger/row inventories. Document fixtures execute the real Actions
through the HTTP adapters. Generation proofs perform a fenced write, not merely create zero rows.

Empty schema forward/rollback/reapply and populated refusal answer different questions; both are
required. Refusal after first use is deliberate, including development (A6). Do not delete evidence,
reset counters or disable guards to make rollback succeed. Backout first disables entry points and
workers; any removal of a used barrier needs a separately approved forward plan. This work adds no
backout that silently removes evidence or activates/grants a duty.

## Related

- [Fence and policy semantics](quotation-access-qa2-qa4.md)
- [Ordered access slices](../phases/phase-4-quotation-record-access-slices.md)
- [Domain index](README.md) · [Testing standards](../architecture/testing-standards.md)
- [Database migration standards](../architecture/database.md#migration-standards)
