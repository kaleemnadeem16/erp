[← Domain index](README.md)

# Job cost coverage

**ACCEPTED 0147 / Q52 resolved, 2026-10-16; bounded contract SPECIFIED, not implemented.**
Coverage assesses which expenses are represented; it neither prices sources nor proves fulfilment.

## Where it sits

Source evidence/recognition → valuation and category coverage independently → overall Job cost view.
Reuse 0110 cost certainty, 0146 labour and 0145 lifecycle; no new universal costing ledger is selected.

## Logical evidence — no migration schema

An immutable assessment records company, Job/activity/category scope, through-date, known-as-of,
actor/recording time, status, basis/evidence, relevant source generation/fingerprint and predecessor.
An invalidation/reassessment records cause/source revision, actor/system identity, time and successor.
Original closeout/report snapshots retain their assessment references; current reports expose changes.

## Behaviour

| Status | Evidence boundary |
|---|---|
| Confirmed Complete | Delivered authoritative-source inventory/reconciliation confirms stated scope/date, not an unlimited audit claim |
| Declared Complete | Authorised person states all known applicable expenses represented within retained basis |
| Incomplete | Known omission, dispute, pending authoritative source or unresolved reconciliation |
| Unassessed | No valid assessment, including stale coverage without a determined omission |

Optional SIMPLE declaration retains all evidence above. Show scoped sources and pending/disputed/
omitted records and valuation gaps. Known omissions/disputes/pending authoritative sources refuse
declaration. A recognised but unpriced source may be represented: coverage complete, valuation
incomplete. Missing rate and missing source remain different. Declared never auto-promotes to confirmed.

New material/contradictory evidence invalidates current complete use: known gap → Incomplete;
otherwise Unassessed pending reassessment. Valuation-only correction can retain coverage only with
dependency proof that the covered source set/scope is unchanged; valuation version still changes.
Do not silently extend through-date. Empty sources never establish zero. Explicit zero assertions
are deferred, with [future requirements](../phases/phase-5-zero-cost-assertion.md) preserved.

Overall cost completeness requires applicable category coverage AND valuation/reconciliation. One
fully valued 15000.0000 labour source proves neither labour coverage nor whole-Job completeness.
Reports retain confirmed versus declared basis rather than silently upgrading certainty in totals.
Optional capture/declaration never blocks supported completion; known obligations still follow 0145.

## Authorisation and data scope

Eligible accountable declaration/reconciliation authority is distinct from recording, recognition,
assignment and cost visibility. Reuse R9 duties only after exact scope proof; no grants added here.
No universal independent approver. Restricted evidence cannot justify a whole-Job claim: refuse
or explicitly narrow supported scope. Guard Actions and Requests; protect history/aggregates/exports.

## Integrity and refusals

Use stable company-scoped assessment/intent identities and exact idempotent receipts. Guard current
authority before replay. Lock Job/source-generation/assessment consistently with recognition and
closeout writers; atomically verify scope/version and append assessment/receipt. Every relevant writer
must invalidate/version coverage, including imports, corrections and future Finance sources. A race
must refuse stale assessment or invalidate it atomically, never leave stale current completeness.
Changed keyed intent conflicts; unique tenant-safe identities prevent duplicate assessments/effects.
Physical schema, reason codes, UI and exact duty vocabulary require separately authorised delivery.

## Acceptance scenarios — not executed tests

- Valued 15000.0000 + unassessed coverage: no complete Job total.
- Recognised unpriced source represented: declared coverage can coexist with incomplete valuation.
- Omission/dispute/pending authoritative source: declaration refused, including through alternate API.
- New expense races declaration: no stale complete assessment; historical statement retained.
- Cost-only correction: prove unchanged coverage dependencies or reassess; never overwrite history.
- Empty list: no zero; completion-only Job can complete without a fabricated expense/declaration.
- Restricted actor/currently revoked retry: refuse claim/disclosure, including receipts and exports.
- Labour covered but materials unresolved: overall cost remains incomplete.

## Landmines

Summing captured money is not coverage. Closing a Job neither strengthens a declaration nor settles
Finance. A source-version scheme that omits one writer cannot support confirmation or safe declarations.

## Related

- [0147](../decisions/0147-bound-job-cost-coverage-to-scope-evidence-and-source-version.md)
- [Labour](job-labour-recognition.md) · [Lifecycle](job-execution-lifecycle.md)
- [Research](../phases/phase-5-coverage-material-resolution.md)
