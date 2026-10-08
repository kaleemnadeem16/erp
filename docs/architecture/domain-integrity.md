[← Architecture](README.md)

# Domain integrity standards — building write operations

How a business write is built so that it stays correct under retries, concurrency, partial failure and callers that never touch HTTP. These are the conventions the stock, purchasing,
approval and package-tracking work established; they are **mandatory for any operation that changes business facts** and are checked by the tests named in
[testing-standards.md](testing-standards.md). Rules that must never be broken live in [invariants.md](invariants.md); this file says *how* to honour them. Security and scope are in
[authorization.md](authorization.md); the checklist that ties everything together is [feature-completion-checklist.md](feature-completion-checklist.md).

## 1. One Action per operation; logic never in a controller

A business operation is a single-purpose `final` Action under its module ([code-structure.md](code-structure.md#actions)). Controllers validate, call the Action and return a Resource; models hold
relations and casts. Anything a second caller (a job, a console command, a seeder, another Action) might need to do goes through the same Action. An Action that outgrows ~300 lines splits by
responsibility behind the same public entry point.

## 2. One transaction, all or nothing

The Action opens the transaction and everything that must be true together commits together: the stock move **and** its package evidence, a QA disposition **and** its package relabelling, a
reversal **and** its draw. A failure at any step — a failed database check included — rolls back every earlier step, including rows written for earlier lines of the same document. Catching an
expected database error inside a transaction needs a nested transaction (a savepoint), or the outer transaction is poisoned. Never post one half and "repair" later.

## 3. Retryable writes are idempotent

Any write a client, job or integration may repeat after a timeout carries a **request key**: the `Idempotency-Key` header for documents, or a `request_key` body field (at most 85 characters) for
operations. The Action stores the key beside a hash of the normalised payload and the actor, under a company-scoped unique index. The same key with the same payload returns the original result;
the same key with a different payload is refused (never merged, never overwritten). A retry still re-checks the caller's current authority and must not move timestamps or predecessors: a
retried posting reuses the original moment and chain position. A write that fans out (a transfer's two legs, a reversal per line) derives each leg's identity deterministically from the key, so a
retry cannot repeat a movement or an evidence row. API shape: [api-conventions.md](api-conventions.md#retryable-writes).

## 4. Lock in a stable order; re-read inside the lock; never rebase silently

Concurrency is handled with pessimistic locks taken in one global order — header before lines, locations in ascending id, the document/PO before the AVCO pool — so two operations cannot deadlock
by taking the same locks in opposite orders. State is read **again after the lock**. Evidence that other work may have moved carries a freshness marker (a stock-move watermark, a latest-evidence id):
if it no longer matches, the operation is refused with the stable code `STALE_EVIDENCE` and the person recounts or refreshes. A draft that captured state (a count line) is refreshed and redone, never
silently rebased onto newer stock.

## 5. Advanced modes are enforced in the database and fail closed

When a progressive mode makes new evidence authoritative ([extensibility.md](extensibility.md#progressive-capability-modes)), correctness must not depend on remembering every writer. The pattern, as
built for grouped package tracking ([ADR 0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md)):

- the mode flips in **one atomic, database-validated transition**, never through a half state; there is no pending mode;
- database triggers reject any write to the guarded tables unless it comes from a package-aware Action, and deferred constraint triggers prove at commit that a move has its evidence, so a script, a
  seeder or a future writer is stopped too;
- a **writer matrix** (`StockWriterMatrixTest`) lists every code path that creates or changes the guarded rows and what it does for the mode — integrated or explicitly refused with a friendly message — so
  a new writer is noticed and given a decision;
- unsupported paths are refused loudly (and by the database), never "best-effort supported";
- the mode is **additive**: items or companies that never opt in behave exactly as before, which a parity test proves;
- leaving the mode (deactivation, changing its configuration) is its own governed design, not a flag flip.

## 6. Immutable, append-only evidence; corrections are new records

Facts that explain money or stock (movements, evidence snapshots, dispositions, approvals, cutover and recalculation records) are append-only: database triggers reject `UPDATE` and `DELETE`
(`reject_*_mutation`), models throw on update, and a correction is a *new* record that references the old one — a reversal, a recount successor, a QA decision — never an edit. Every governed change records
actor, time and a mandatory reason; the reason is stored with the evidence, not only in a log. The `activity_log` is the generic audit trail; domain evidence tables are the authoritative history and must
be reconstructable without it. A record that has been used (issued, posted, adopted) is frozen; its later changes are new versions ([invariants.md](invariants.md#4-versioned-records-are-immutable-once-used)).

## 7. Put invariants in the database, then prove them

If an invariant protects money, quantity, tenancy or evidence, enforce it in PostgreSQL as well as in the Action: composite company foreign keys and uniques, `CHECK` constraints, conservation checks
as deferred constraint triggers, immutability triggers. The Action's checks give friendly messages and early refusal; the database is the last line and the one tests attack directly with raw SQL
([database.md](database.md#migration-standards)). A database refusal is mapped to a 422/409 by the global handlers ([api-conventions.md](api-conventions.md#errors)); it should still be rare, because the
Action refused first.

## 8. Money and quantity precision

Money is `decimal(19,4)`, never a float, in migrations, models, fixtures and examples ([invariants.md](invariants.md#6-money-is-decimal194-never-float)). Stock and package quantities are four-decimal
strings in PHP (`BigDecimal`) and in JSON; the frontend never does money or quantity arithmetic with floats (it uses string/BigInt helpers such as `quantity.ts`). Rounding is explicit, named and done once
at a documented point (allocation remainders go to a stated line); a conversion factor that cannot represent a quantity exactly is refused, not rounded. Quantities finer than the ledger are refused.

## 9. Statuses and lifecycles

A status is a backed enum with named transitions, each a named Action with its own permission and reason where governed (`issue`, `post`, `reverse`, `approve` — never a generic "update status"). The set of
allowed actions is derived from server state and permission, and returned to the client rather than re-derived. Terminal states stay terminal; "undo" is a new, named correcting transaction.

## 10. Snapshot at issue; reproducible history

Anything priced, costed or interpreted at a moment (a PO line's price basis and package, a cost roll-up, a material plan) stores the inputs it used, so changing a master later cannot change what an issued
document meant ([invariants.md](invariants.md#1-snapshot-on-issue)). History must be explainable from stored rows: evidence that can be replayed, not a current-state guess.

## Mandatory versus advisory

| Mandatory for any write that changes business facts | Advisory (decide per feature, record the decision) |
|---|---|
| Action owns the logic; one transaction; rollback on failure | Optimistic-concurrency tokens for form edits |
| Idempotency key for retryable or integration-facing writes | Event announcement to other modules |
| Stable lock order; re-read after lock; stale refusal with `STALE_EVIDENCE` | Materialised read models |
| Database enforcement for tenancy, conservation and immutability invariants | A queue for long work (see [feature-completion-checklist.md](feature-completion-checklist.md#operational-concerns)) |
| Fail-closed behaviour and a writer matrix for any authoritative mode | |
| Append-only evidence, mandatory reason for governed changes | |
| `decimal(19,4)` / string quantities, no floats | |

## Related

- [invariants.md](invariants.md) · [code-structure.md](code-structure.md) · [database.md](database.md) · [multi-tenancy.md](multi-tenancy.md)
- [authorization.md](authorization.md) — guard twice, scope, rollout
- [testing-standards.md](testing-standards.md) — how each rule above is proved
- [package-issue-operations.md](../domain/package-issue-operations.md) — the reference implementation of §§3–6
- [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md), [ADR 0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md)
