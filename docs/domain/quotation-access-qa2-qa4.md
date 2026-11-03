# Quotation access fence and list (QA2–QA4a)

This branch extends the QA1b quotation-family OWN/ALL foundation with protected
draft reads, writes, ownership changes, replay and a safe quotation list.
Shipped quotation duties remain STAGED. This is not an activation or a real
holder recipe.

Every protected decision needs a current active member, the exact active Sales
duty, the actual persisted grant and the supported family scope for that duty.
Required duties are intersected. OWN reaches only a family's current responsible
owner, excluding unresolved historical ownership; unrestricted ALL reaches
every family in the same company, including unresolved families. An unrelated
duty, a team membership, creator identity or a named share never enlarges that
reach. Edit, preview and draft updates require both view and prepare. Assignment
and baseline recovery independently require assign and view.

A capability denial is 403 before a record lookup. Hidden, foreign and missing
revisions use the same quotation-not-found refusal; the HTTP adapter maps it to
404. CRM opportunity references are suppressed unless the actor independently
has a current pipeline read capability. Source and Party checks remain
independent.

`CompanyAccessFence` protects a decision within a database transaction.
Readers take its shared company lock through materialising their safe result.
Registered security and family database writers take an exclusive statement-level barrier; the
current conservative implementation creates and locks every company row in
ascending ID order, including for raw SQL. Draft mutations take those locks
before validation, family/revision locks, receipt lookup and work. The same
key can replay only after current family eligibility is checked. A new draft
receipt rechecks the resulting family before disclosure. PostgreSQL aborts
deadlocks rather than allowing an unprotected result; the complete lock graph
and retry behavior still require independent review.

The conservative all-company barrier is a correctness-first implementation,
not a scale claim. It serialises unrelated security and family writes and needs
production contention and lock-order review. The user authorization generation
column is reserved; individual-user epoch invalidation, sharing, team access,
delegation and non-revival rules are not implemented here. Deployment-time
changes to authority providers must be coordinated and reviewed before
activation. Do not regard the presence of a counter as an allow decision.

The index endpoint `GET /api/v1/quotations` applies the family selector
before filtering, sorting, count and pagination. Rows contain only revision and
family IDs, ordinal, state, recipient and currency IDs and creation time.
Supported filters are state, recipient, owner and mine; supported sorts are ID,
creation time and state, with a stable ID tie-break. No price, cost, margin,
tax facts, source detail or hidden-record counts are included. The local demo
preparer is OWN-scoped; a separately authorised unrestricted local viewer can
list all local-company quotations.

Issue and withdrawal duties are not yet supported by this scope adapter. Their
future Actions must register their own exact duties and enforce current family
eligibility, Tax/source/control evidence and lock order before replay or
mutation. The separate Tax facts work still needs a correction/supersession
lifecycle, independent review and the issue consumer; illustrative demo data
is not legal Tax configuration.

Related: [QA1b scope](quotation-own-scope-qa1b.md),
[record access design](quotation-record-access-design.md), and
[storage and locking proposal](quotation-record-access-storage.md).

## Barrier scope, retry, rollback and the activation gate (Agent B review follow-up)

- **Column-aware `users` barrier.** The `users` table is fenced on INSERT, DELETE and TRUNCATE and on an UPDATE that SETS `company_id`, `status` or
  `authorization_generation` (`BEFORE UPDATE OF …`). A sign-in (`last_login_at`), remember token, name or password write never takes the all-company lock and never
  advances a generation, so a login in one tenant cannot queue behind, or stall, another tenant's protected read or issue. Every other fenced table is still fenced on
  any write. Tests: a sign-in neither bumps nor waits (two sessions, with a mutation's `lockAll` and a reader's `lock` open); a status or membership change still does.
- **Deadlock / serialisation victims.** `QuotationOperation::run` restarts the WHOLE operation (fence, authority in the subject lock, receipt look-up, work) up to
  `DEADLOCK_RETRIES` times on SQLSTATE 40P01 / 40001, only as the outermost transaction; inside a caller's transaction the failure returns to the caller.
- **New writers.** An architecture-style test in `CompanyAccessFenceTest` fails when a table with a foreign key to roles, permissions, organisation units or scope limits
  is neither behind the barrier nor named in its exempt list with the reason it cannot change a quotation access decision.
- **Rollback is one-way once used.** Every fenced write advances a generation, so after the first real security write the barrier migrations refuse `down()`
  ("requires a separately approved backout"). That is deliberate: dropping the barrier while evidence of its use exists would hide invalidations. A backout needs its own
  approved plan (disable activation first, then remove the triggers under an exclusive lock).
- **Pre-activation gate (before any holder recipe activates quotation duties).** The barrier serialises security and family writes across ALL companies. Before activation
  the owner needs measurements, not a claim: set a `lock_timeout` for the barrier statements and for protected readers, measure the write and read latency and the lock-wait
  distribution under a realistic mixed load (logins, member/role edits, drafts, lists, issues), and confirm that a security write in one tenant does not breach the other
  tenants' latency budget. If it does, the next step is a per-company barrier with a proven ordering rule, not a longer timeout.

## Integration baseline decisions (final integration onto develop)

Recorded for the owner; each follows the QA3 rule above (OWN = the current responsible owner acts on their own records, ALL explicit, an
unrelated duty never widens, anything else default-deny).

- **Document and delivery routes use this policy.** Each document Action requires its exact document duty company-wide (document duties have no
  family scope, so a limited grant is refused), then the independent quotation view through `QuotationAccess` (OWN/ALL) under the fence. The
  Documents owner gate (`QuotationCustomerDocumentAdapter`) asks the same policy. Hidden, foreign, unknown and unissued revisions are one 404.
- **Sales → Tax context authority** judges prepare or issue for the exact revision through `QuotationAccess`, so an OWN preparer gets Tax
  determinations for their own draft.
- **Control evaluation.** `EvaluateAssemblyLineControls` takes the revision it evaluates for and requires prepare for that revision; the
  cost-evidence authority (a person-level callback with no revision) accepts a prepare grant whose scope the policy supports (OWN or ALL) and
  refuses any other.
- **Issue HTTP surface.** `GET …/issue-readiness` (prepare + view), `POST …/issue` (issue + view; required `Idempotency-Key`,
  `expected_generation` in the body), `GET …/issued` (view). The issued record carries the official number, instant, maker/checker and frozen
  header terms; settled totals only for a caller who also holds prepare or issue for that revision. Never cost, margin, controls outcome,
  fingerprints, tax evidence or numbering references.
- **List summary.** Includes `valid_through_local_date`, `responsible_owner_id`, official number and derived availability. The integrated pricing
  follow-up judges recipient Party read and exact quotation commercial-view eligibility independently per row: unreadable/absent values are null.
  Only frozen issued net is projected; draft previews are never recomputed by the list. Commercial-view remains STAGED. Eligibility, count and page
  share one SQL snapshot; counts never include hidden families.
- **Fence reads are pure SELECTs.** `CompanyAccessFence::lock()` inserts a company's barrier row only when it is missing.

## QA4 read-only inventory checkpoint

`sales:audit-quotation-access --company=<id>` is the server-operated preparation report. It
requires an explicit existing company and runs in PostgreSQL REPEATABLE READ, READ ONLY. It lists
all quotation/document/delivery role and direct paths, their stored assignment scope, unrestricted
paths, unit memberships and configured full-access mandate candidates. Generic mandate qualification
is explicitly **not** reviewed quotation rollout approval. Unassigned roles and suspended members
are included for review. No password/token columns are queried; nothing is registered, granted,
activated or applied. Do not run it against the owner's database during development verification.

The command is only the inventory portion of QA4. Holder-recipe code, origin ledger, dry-run/backout
proofs and staging sign-off remain outstanding. The approved demo exception is separately recorded
in [0154](../decisions/0154-quotation-demo-access-and-activation-boundaries.md) and the
[persona/preset contract](quotation-access-demo.md); it is not real-holder activation.

## Related

[Safety proofs and parameterised synthetic harness](quotation-access-safety-proofs.md) records
bounded waits, the real upgrade-deadlock reproduction, supported writer ordering and populated
rollback proofs. Production activation remains blocked pending owner-supplied representative
company counts, concurrent workload profile and latency budgets.

[Advisory corrections](quotation-access-advisories.md) records the current-actor, retry, list
snapshot and safe-history changes, with retained fail-closed rules and remaining integration checks.
