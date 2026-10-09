[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-B3 — Bounded business direction and acceptance readiness

- **Date:** 2026-10-08.
- **Status:** R1 business research complete 2026-10-09; accepted ADRs 0098–0108 govern; detailed delivery gates remain.
  ADRs 0100–0102 accepted, bounded B3 business research closed. No delivery authorised.
- **Research reuse:** the linked R1 comparison records sufficiently distinguish the alternatives;
  this reconciliation records the owner's answer rather than repeating vendor research.

**Final closure:** the owner approved the bounded foundation contracts; [current R1 closure](phase-4-r1-decision-matrix.md)
records accepted decisions and delivery gates. Earlier alternatives and review agendas below are
historical unless confirmed by those ADRs; they do not reopen R1 or approve detailed implementation.

## Business meaning

R1-B3 separates confirmed accepted business, additional remaining potential and the evidence or
assumptions behind it. Independent scopes may be accepted separately; explicit exclusive groups
identify competing alternatives. Revisions are not additional business. MAINTAINED is the default
remaining estimate; SELECTED_OFFERS explicitly chooses eligible, non-competing issued revisions.
Neither every associated quote nor accepted work is automatically added into remaining potential.

Acceptance uses authoritative evidence and does not silently subtract from a maintained estimate.
Potentially stale assumptions require review. Ineligible selections are flagged, never silently
replaced. A forecast requiring review remains provisional until explicit authorised review and
successful republication; technical recovery alone cannot restore trust. Multiple reasons may
coexist with that persistent requirement.

SPOT valuation uses an explicit date and preserves source currencies, actual rate values/dates,
provenance and publication history. Ordinary later-date rate movement alone does not cause
staleness: a publication remains trusted at its date if otherwise valid. Corrections to a rate
actually relied upon need separate impact assessment, not automatic historical rewriting.

## Practical example

On 8 October, USD `1000.0000` plus PKR `300000.0000` at `280.000000000` PKR/USD publishes
PKR `580000.0000`. A new 15 October rate of `285.000000000` leaves that publication unchanged and
trusted at 8 October. An authorised new 15 October publication gives PKR `585000.0000`, assuming
the same eligible business amounts and all review/approval checks pass.

If a selected offer expires meanwhile, the forecast requires review regardless of the new rate.
Renewing the offer or obtaining a missing rate does not itself restore trust. If the original
8 October rate is corrected, preserve `280.000000000` as the actual publication evidence and
assess the correction under R4/R9; that event is not ordinary later-date movement.

## Consequences and acceptance recommendation

**No genuinely blocking R1-B3 business choice remains within this bounded scope.** The reviewed
contracts passed the final consistency review and were accepted by owner authorisation. This does not close whole R1/Q39
or authorise delivery of features whose delegated semantics are still unknown.

| ADR | Final acceptance | Remaining delivery boundary |
|---|---|---|
| 0098 — hybrid workflow | Accepted 2026-10-09 after final U1/U2 review | STRICT replacement/archive-only legacy and policy governance settled; detailed protocols R5/R9 |
| 0099 — issue versus CRM association | Accepted 2026-10-09 after final U1/U2 review | Family association, explicit Party resolution, OPEN targets settled; exceptional corrections and physical scope R2/R5/R6/R7/R9 |
| 0100 — independent scopes/exclusive groups | Accepted 2026-10-09 — bounded business direction | R2 identity/overlap and post-issue regrouping; R6 accepted-scope adjustments; R7 handover. No implied permission for unresolved operations |
| 0101 — accepted versus remaining forecast | Accepted 2026-10-09 — bounded business direction | R2/R6 exclusion calculations; R4 valuation details; R5/R9 eligibility/review implementation |
| 0102 — persistent trust/explicit valuation | Accepted 2026-10-09 — bounded business direction | R4/R9 historical corrections/reporting; R5 lifecycle detection; exact capabilities/approval exposure remain R9 |

ADRs **0098–0108 are accepted** after final owner review. The final review checked their
wording against the owner's scope/group, forecast-method, persistent review and ordinary FX
movement decisions; no material ambiguity, contradiction or new business choice was found.
Accepted architectural constraints remain compatible; the table below preserves all delegated
R2/R4/R5/R6/R7/R9 questions as unresolved delivery gates. Acceptance closes only bounded B3
business research, not B3 implementation, whole R1, Q39 or remaining Phase 4 rules. Unresolved
operations remain unsupported until their own decisions. Future changes to these accepted ADRs
require a new superseding ADR; no edit to an accepted contract is permitted.

## Reconciliation and technical implications

No conflict was found with accepted currency/FX, audit, authorisation or approval decisions.
ADRs 0012/0031 require pinned dual amounts and manual rates; live historical revaluation or
automatic rate fetching would conflict. ADR 0081 keeps Sales policy module-owned. ADRs 0070/0085
keep approval tied to the actual reviewed evidence. Existing tenant, scope, Action guards,
append-only evidence and retry/concurrency invariants remain applicable.

The existing reference-rate maintenance permits corrections/deletion: copied values and sources,
not only live references, are necessary. Publication must atomically validate current authority,
basis and rates; retries return the original result and failures create no false successful
publication. Trust transitions need durable history alongside immutable publication evidence.
No physical tables, state enums, scheduler or capability catalogue are selected here.

Reports distinguish historical publication, currently trusted forecast, provisional forecast and
optional indicative current-rate valuation. Show valuation dates/currencies. Aggregating different
bases requires an explicit consistently applied rule; separate labels alone are insufficient.
Earlier research examples do not authorise mixed-basis production totals.

| Package | Detailed questions transferred; dependent delivery remains gated |
|---|---|
| R2 | Quotation scope identity, revisions/families, overlapping/dependent offers and post-issue grouping |
| R4 | FX fallback, rate age, rounding, corrections and accepted-commitment valuation |
| R5 | Expiry, withdrawal, supersession, revision/renewal lifecycle and eligibility boundaries |
| R6 | Partial acceptance, accepted-scope exclusions, cancellation/correction and adjustments |
| R7 | Job handover and one/multiple jobs for multiple accepted scopes |
| R9 | Review triggers/materiality, permissions, reporting rules, corrections' impact, historical/as-of analytics and trust history |

## Recommended next research

**R1-C — customer/prospect onboarding.** Resolve quotation recipient identity and the transition
from enquiry/prospect to commercial customer using the existing Party/account foundations. Do not
presume person customers or configurable teams already exist. This directly supports the open
0099 relationship questions. R1-D–G are accepted in ADRs 0105–0108; detailed FX/reporting work belongs to R4/R9 rather
than another expansion of B3. C1 direction is accepted in ADR 0103; C2 separation is accepted in ADR 0104; [remaining R1 choices](phase-4-r1-decision-matrix.md) are for review. No implementation.

## Related

- [R1](phase-4-r1-leads-and-relationships.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Offers comparison](phase-4-r1-b3-offers-and-forecast.md) · [Forecast basis](phase-4-r1-b3-forecast-basis.md)
- [Staleness](phase-4-r1-b3-forecast-staleness.md) · [FX](phase-4-r1-b3-forecast-fx.md)
- [ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md) · [ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md)
- [ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md) · [ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md) · [ADR 0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md)
- [Research conventions](../architecture/research-and-design.md) · [Invariants](../architecture/invariants.md)
