[← Domain](README.md)

# Quotation access generation — proposed sharing extension

**2026-11-05: owner decision pending; not implemented.** This records the concrete implementation
choice raised on #77 while QA5 actor-epoch tests continue. It grants/activates nothing. Existing
migration files and accepted ADR decisions remain unchanged.

## Contract gap

The implemented QA1a database contract couples family access_generation to the latest ownership
event. `quotation_ownership_event_insert_guard()` allows that counter to move only on a unit change;
`quotation_family_ownership_check()` requires the family and latest event to have equal values.
Source: `2026_10_27_100000_add_quotation_ownership_and_evidence.php:112` and `:157`.

The [proposed QA5 storage contract](quotation-record-access-storage.md) requires a grant/revocation
to advance access_generation without advancing ownership_generation. Owner/unit transfer separately
invalidates share pins. A legitimate share mutation therefore fails the current deferred guard.
Skipping the guard, fabricating an ownership event or silently leaving the access counter unchanged
would violate the intended evidence/staleness contracts.

## Recommended extension for owner review

Add an append-only `quotation_access_events` ledger through a new additive migration. It provides
counter consistency evidence; record eligibility remains in QuotationAccess and typed share evidence.

```text
quotation_access_events
  id, company_id, family_id, generation (> 0), previous_event_id
  kind OWNERSHIP_CHANGE | SHARE_GRANTED | SHARE_REVOKED
  ownership_event_id | grant_id | revocation_id     -- exactly one typed source
  recorded_at (database clock), origin
  UNIQUE(company_id,id), UNIQUE(company_id,family_id,generation)
  each source recorded at most once
  same-company/family composite FKs, including predecessor/source
```

- Existing initial ownership at access generation 0 needs no tick. Captured baselines and unit
  changes supply ownership ticks. Owner reassignment retains its distinct ownership generation
  and current access snapshot; it never fabricates a unit change.
- Grants/revocations append their typed source and next access tick in the same transaction.
  The security fence precedes the family lock. A tick must continue the latest same-family
  predecessor and match the family's next access generation; it cannot reuse a source.
- At commit, owner/unit/baseline/ownership generation still equal the latest ownership event.
  Family access generation must equal the access ledger tail. Raw projection-only changes,
  missing ticks, skipped/duplicate generations, wrong-family sources and unsupported source kinds
  refuse. The ownership-event insertion check uses the locked current access generation, so
  intervening shares cannot break the next legitimate ownership transition.
- Preserve existing ownership rows and their snapshots. Derive initial ledger rows from their
  immutable captured-baseline/unit-change evidence under migration locks. Mark their origin as
  backfill and their new ledger recording time truthfully; retain the original event reference
  rather than pretending the new ledger existed historically.
- New evidence refuses UPDATE, DELETE and TRUNCATE. Populated rollback refuses; a pristine
  rollback restores the earlier function definitions. No old migration file is edited.
- Existing ownership/tenant/raw-write/concurrency tests remain, with new grant/revoke/transfer
  and counter-chain attacks. This extension must prove equal-or-stronger evidence guarantees;
  permission/record-scope checks receive no exception.

This changes the access-counter accounting in new function definitions, while retaining owner/unit
identity and historical-event checks. It is not applied while the owner decision is pending.

## Alternative for owner review

A separate family sharing_generation column could leave the old ownership/access coupling intact.
It would require a separate expected-sharing-generation write contract, client/UI exposure and
explicit coordination with owner/unit changes. This differs from the planned common access-generation
contract and needs its own accepted decision. Neither option changes which person may delegate.

## Unchanged limits

Shipped duties remain STAGED; production activation remains blocked. Grants require actual,
independently scoped duties and fresh same-company actors. Prepare still requires view + prepare;
management needs non-delegated authority. The demo operator bootstrap is a separately approved
first-holder exception requiring exact-SHA review and CI before merge. No owner database is used
for design or verification.

This is a local consistency/evidence decision. It reuses [QA5 design and research](quotation-record-access-design.md)
and [the storage plan](quotation-record-access-storage.md); it does not introduce new ERP sharing
business semantics or require another market comparison.

## Related

- [Current access and epoch checkpoints](quotation-access-qa2-qa4.md)
- [Resume checkpoint](quotation-access-resume.md)
- [0155 bootstrap limits](../decisions/0155-bootstrap-only-the-synthetic-demo-access-operator.md)
- [0156 demo boundary](../decisions/0156-quotation-demo-access-and-activation-boundaries.md)
- [Issue #77](https://github.com/kaleemnadeem16/erp/issues/77)
