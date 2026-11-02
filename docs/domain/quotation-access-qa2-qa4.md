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
