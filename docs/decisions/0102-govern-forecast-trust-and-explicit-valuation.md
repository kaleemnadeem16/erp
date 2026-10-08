[← Decisions](README.md)

# 0102 — Restore forecast trust only through explicit review and publication

- **Status:** proposed — owner-reviewed staleness/valuation direction; not accepted/finalised.
- **Date:** 2026-10-08
- **Affects:** Phase 4 R1-B3, review/publication, forecast FX, historical reporting and governance.
- **Gate:** research/documentation only; no schema, enum, migration or implementation selected.

## Context

Proposed ADR 0101 separates accepted commitment, remaining potential and forecast basis. A valid
technical condition does not prove that commercial assumptions are current: a selected offer may
expire and later be renewed while the buyer's requirements have changed. A live rate display
can also change an apparent total without any new business or reviewed valuation.

The owner reviewed [staleness](../phases/phase-4-r1-b3-forecast-staleness.md) and
[FX research](../phases/phase-4-r1-b3-forecast-fx.md). This record preserves that direction;
topic-specific questions remain open before final acceptance.

## Decision — owner-reviewed proposed direction

### Review is persistent until explicitly republished

A forecast requiring review remains **provisional** until an authorised person explicitly reviews
and successfully republishes it. Preserve its last published amount, valuation date, basis and
review/publication evidence. Exclude invalid, incomplete or review-needed forecasts from trusted
active totals; show them separately with a clear provisional label. Missing/untrusted is not zero.

Individually eligible components may be shown for information, but their sum does not automatically
validate the intended whole forecast. Provisional whole-forecast values and component subtotals
are different presentations of the same underlying business, never additive measures.
No automatic substitute, "latest revision" selection or automatic restoration of trust is allowed.
An issue disappearing clears the applicable diagnostic, not the need for review/publication.

Example: furniture `500000.0000` PKR plus installation `300000.0000` produces a published
`800000.0000` forecast. Furniture expires; the basis becomes provisional. Renewal does not restore
trusted status. An authorised review and a new valid publication are required, even if the
reviewer confirms the same number. Historical publication remains unchanged.

### Trust and diagnoses — recommendation, not accepted state schema

Prefer a **trust/review condition plus multiple diagnostic reasons** over a single mutually
exclusive status. A forecast may need review, contain an invalid selected offer and lack an FX
rate simultaneously. CURRENT can describe a trusted, currently valid publication;
NEEDS_REVIEW describes review obligation; INVALID_BASIS and INCOMPLETE_VALUATION explain
blocking conditions. These are conceptual labels, not prescribed database enums.

A current eligibility test can remove a diagnostic but cannot clear the durable review obligation.
Successful publication requires eligible basis, complete valuation, current review evidence and
any applicable approval. Review alone cannot override missing rates or commercial eligibility.
Expiry before a renewal must remain detectable even if nobody viewed the forecast during the gap:
a current fingerprint alone cannot prove that no invalidating event occurred. R5/R9 must choose
event/version/history detection and date-boundary semantics; no scheduler is implicitly authorised.
Viewer-specific access refusal is distinct from global commercial invalidity; a restricted reader
must not turn a valid company forecast provisional solely because they cannot see one component.

### Maintained estimates and review evidence

Material acceptance or other relevant business changes may trigger review, but never silently
subtract accepted amounts from MAINTAINED remaining estimates. Preserve the prior estimate and
explain the trigger. The reviewer can confirm it unchanged or enter a revised figure; successful
review/publication creates a new immutable forecast version either way. Retrying that same
publication does not create another version. An ordinary no-change save is not a publication.

Record actor/time, previous/new basis, relevant intervening changes, the evidence reviewed and
publication result. The exact list of mandatory triggers vs informational warnings remains under
research. Drafting a new alternative, an unrelated deal event or review age alone is not implicitly
chosen as a mandatory invalidation. Partial acceptance/overlap/cancellation remain R2/R6.

### Permission-based review, optional approval

Separate editing assumptions, reviewing/confirming validity, and approving publication if policy
requires it. Simple companies can review/publish using explicit capabilities and record scope;
do not require a manager, separate checker or managerial approval for every forecast by default.
One person may hold edit and review duties; separation-of-duties is policy, not a role-name rule.
Exact capability names and combined UI action are R9 design questions.

If later configured, use the existing Approvals engine and a forecast subject/evidence provider,
never a separate engine. Approval is not review, and review is not an approval decision. Approval
must apply to the basis/valuation being published; relevant changes invalidate stale approval
under ADRs 0070/0085. Approval does not make an invalid basis valid or grant opportunity-policy
bypass, acceptance, commercial-edit or exchange-rate-maintenance authority.

### Explicit SPOT valuation

Use SPOT at an explicit forecast valuation date from existing exchange-rate records and agreed
fallback rules. Every published valuation captures reporting currency, date, source amounts,
rate values/actual dates/provenance and results. Explicit FX refresh produces a new valuation/
publication version; viewing or changed reference rates do not mutate or republish prior versions.

Example: USD `1000.0000` + PKR `300000.0000`, SPOT `280.000000000` on 8 October gives
`580000.0000` PKR. Explicit valuation on 15 October at `285.000000000` gives `585000.0000` in a
new version. The 8 October forecast and quotation issue FX remain unchanged.

Missing rates mean incomplete valuation. Show eligible currency contributions without labelling
a convertible subtotal as a complete forecast. Show fallback rate age/effective date. Preserve
quotation issue FX, forecast valuation FX and accepted commercial commitment evidence separately;
forecast management views are not accounting revenue recognition or GL revaluation.

The owner's preferred direction is that FX changes alone do not silently revalue/republish. Whether
new rates should create an informational warning or a mandatory review condition is still open.
Exact fallback resolution, maximum age, accepted-commitment management conversion and refresh of
already stale bases remain R4/R6/R9 questions. Changing a rate source row cannot rewrite snapshots.

## Technical/reporting invariants — proposed application of existing standards

1. Publications/valuations are immutable evidence; changes and successful reviews create versions.
2. Provisional cannot silently re-enter trusted totals, even after technical recovery.
3. Trusted current totals exclude invalid, incomplete and review-needed contributions; provisional
   whole-forecast and component presentations cannot double-count or imply unknown is zero.
4. Historical reports use the publication/valuation and trust evidence applicable at the requested
   point in time. Recorded-at vs effective-at/backdated reporting must be decided under R9.
5. Publication is atomic and retry-safe; failed review/FX calculation creates no successful trusted
   publication or false approval/valuation evidence. Current actor authority is rechecked.
6. Company, record and sensitive-field scope apply to operations, reports, histories and exports.
7. Expiry, supersession, withdrawal, acceptance and relevant opportunity/group changes can invalidate
   basis or require review. Full trigger classification remains open; document creation alone is
   not equivalent to supersession.
8. Concurrent edit/review, rate correction, FX refresh, group/CRM/quotation change or acceptance
   cannot publish an obsolete basis as trusted. Re-read/version-check affected facts at publication
   with stable lock order and a defined serialisation point; do not rely on browser preflight.

R2/R4/R5/R6/R9 specify source identities, locks and database enforcement; this proposal fixes the
needed guarantees without selecting physical tables. Historical trust transitions need durable
evidence outside frozen publication content; overwriting its status would violate immutability.
An explicit FX refresh may calculate a provisional candidate while commercial review is pending,
but cannot restore trust by calculation alone. Whether candidate-only refresh is supported is
still a design question; no implicit successful publication of an invalid basis is permitted.

## Reconciliation and conflicts

Compatible with proposed ADRs 0098–0101: opportunity entry controls, issue/current CRM separation,
exclusive offers and authoritative acceptance remain independent. No accepted ADR is superseded.

ADRs 0012/0031 preserve dual amounts, pinned history and manual rates; latest-rate-on-read changing
stored base amounts or automated fetching would conflict. Module-owned Sales policy remains under
ADR 0081; future money-carrying Sales records register the financial-activity probe. Approvals use
ADRs 0070/0085; capability/scope/guard rules use ADRs 0028/0089/0096. The current rate Action allows
correction/deletion, so snapshots must copy actual rate/date, not rely on a live FK alone.

## Consequences and alternatives

- **Positive:** recovered technical validity cannot disguise outdated commercial assumptions;
  published management figures stay reproducible across rate changes.
- **Negative:** explicit republication adds operator work and some forecasts leave trusted totals
  while known components remain valid; diagnosis/review history needs careful reporting.
- **Requires:** explicit review duties, freshness evidence, concurrent/retry/database proof and
  accurate provisional/unknown handling before implementation.

**Automatic recovery or automatic latest-offer substitution** — rejected by owner direction.
**One exclusive diagnostic status** — not recommended; loses coexisting issue explanations.
**Every forecast needs a manager's approval** — rejected; permission-based review is sufficient
unless a configured approval policy says otherwise. **Live revaluation on read** — rejected as
publication behaviour. **Automatically reduce manual estimates on acceptance** — rejected.

## Research basis and remaining topic ownership

Reuse dated [staleness comparison](../phases/phase-4-r1-b3-forecast-staleness.md#primary-source-comparison)
and [FX comparison](../phases/phase-4-r1-b3-forecast-fx.md#primary-comparison), accessed 2026-10-08;
Dynamics refresh/display FX, Odoo offer expiry and ERPNext dated/stale rates distinguish the
alternatives. Persistent review is the owner's chosen control, not a universal vendor rule.
Existing currency/audit/domain-integrity decisions supply evidence/transaction invariants; no
ceremonial repeat search is needed to record this review.

- **R1-B3 remaining small choice:** does FX movement alone warrant a review obligation, or an
  informational indication while the reviewed business basis stays trusted at its published date?
- **R4:** exact fallback/type/pair/date/age, rounding, reporting currency and revaluation semantics.
- **R5:** issue, renewal/revision/supersession and expiry boundary/eligible states.
- **R6/R2:** acceptance/partial/overlap/correction and group/dependency consequences.
- **R7/R1-F:** job handover and partial-win/closure; no decision made here.
- **R9:** mandatory review triggers vs warnings, duties/approval exposure, trust diagnostics,
  publication/report as-of/recorded-at and scope, concurrency/history implementation design.

R1-B3 stays open until these dependencies have an explicit reconciliation path; topic-specific
implementation detail need not be designed inside R1. No premature final acceptance.

## Related

- [ADR 0101](0101-separate-accepted-business-and-remaining-forecast.md) · [ADR 0100](0100-independent-quotation-scopes-and-alternative-groups.md)
- [Staleness](../phases/phase-4-r1-b3-forecast-staleness.md) · [FX](../phases/phase-4-r1-b3-forecast-fx.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Multi-currency](../domain/multi-currency.md) · [Authorisation](../architecture/authorization.md) · [Domain integrity](../architecture/domain-integrity.md)
