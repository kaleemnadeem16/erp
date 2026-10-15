# Sales

The CRM foundation of Phase 4: optional leads, opportunities with one accountable owner, configurable OPEN stages, stable outcomes and the history behind them.
Quotations, pricing, tax, acceptance and job handover are **not** here yet — they wait for their accepted decisions (R2–R8).

**Governed by:** [ADR 0098](../../../../docs/decisions/0098-hybrid-sales-opportunity-requirement.md) (optional lead, direct opportunity),
[0105](../../../../docs/decisions/0105-support-generic-party-identity-and-conversion.md) (identity reuse and conversion),
[0106](../../../../docs/decisions/0106-separate-sales-ownership-and-access.md) (ownership is not access),
[0107](../../../../docs/decisions/0107-separate-opportunity-stages-and-outcomes.md) (stages and outcomes),
[0108](../../../../docs/decisions/0108-expose-a-progressive-basic-crm.md) (basic CRM). Domain: [sales-pipeline.md](../../../../docs/domain/sales-pipeline.md).

## Accepted correction handoff — slices S, A, C and B implemented

As-built record and rollout steps: [implementation handoff](../../../../docs/phases/phase-4-crm-corrections-implementation.md). Delivered: per-duty record scope (`SalesAccess`), scope-aware Party candidate eligibility, independent keyed disqualification (`DecideLead::disqualify` through `CrmOperation`, receipts in `sales_crm_operation_receipts`), and immutable intake evidence with governed enquiry corrections (`RecordLead` writes the intake atomically; `CorrectLeadEnquiry` replaces the old NEW-only `UpdateLead`; evidence in `sales_lead_intakes`, `sales_lead_enquiry_corrections` and `_fields`, with database guards). Historical attribution corrections (B): `CorrectOpportunityAttribution` appends to `sales_opportunity_attribution_corrections` (one chain per recorded owner fact, database-validated against the frozen source event, append-only) and never writes the opportunity; `ReadOpportunityAttributionMembers` is the narrow selector; `sales.opportunity.attribution.correct` is excluded from every automatic all-capabilities grant (`PermissionRegistry::excludeFromAutomaticGrants`, `FullAccessMandates`).

[0111](../../../../docs/decisions/0111-disqualify-leads-independently-of-derived-opportunities.md),
[0112](../../../../docs/decisions/0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md)
and [0113](../../../../docs/decisions/0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md)
extend the foundation with independent disqualification, historical attribution evidence and original/current
enquiry preservation. [Specification](../../../../docs/phases/phase-4-crm-correction-specification.md)
and [domain model](../../../../docs/domain/crm-corrections.md) describe planned Actions/constraints/UI.
Current Actions below still have their original correction restrictions. B's duty/grant boundary is
accepted in [0114](../../../../docs/decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md);
safe registry/grant/re-sync integration remains delivery work. No general closed-owner editor or named
administrative assignee is approved.
[Security correction](../../../../docs/phases/phase-4-crm-security-correction.md) remains urgent separately.

## Owns

`Lead`, `Opportunity`, `SalesStage`, `SalesNote`, `SalesHistory` (the append-only decision log). A lead/opportunity points at a `Party` (and optionally one of its contacts); it never creates a customer account.

## Key actions

| Action | Purpose |
|---|---|
| `RecordLead`, `UpdateLead`, `LinkLeadParty`, `AssignLead`, `DecideLead` (`qualify` / `disqualify` / `revive`) | The optional enquiry, kept as received; every decision is a history row with its reason |
| `CreateOpportunity` | Direct, or converting a lead; `conversion_key` makes a retry return the deal already created; owner must be an active member |
| `UpdateOpportunity`, `ChangeOpportunityStage`, `ReassignOpportunity` | Keep the open deal current; stage names and owners are preserved in history |
| `CloseOpportunity`, `ReopenOpportunity` | LOST / ABANDONED with a reason; reopen the same pursuit. **WON is refused** until recorded customer acceptance exists |
| `AddSalesNote`, `MaintainSalesStages` | Authored notes; stage configuration (archive, never delete; the last active stage stays) |

## Internal financial and pricing kernel (Phase 4 batch B1)

Internal only — no route, table, permission, grant or UI; nothing here is reachable from HTTP. Contracts: [quotation-financial-calculation.md](../../../../docs/domain/quotation-financial-calculation.md) (FR0) and [sales-pricing-resolution.md](../../../../docs/domain/sales-pricing-resolution.md) (PR0).

- **`Support/Financial` (FR0).** `StorageAmount` is the decimal(19,4) adapter: non-negative, at most four places, never rounded silently. `Arithmetic` multiplies exactly (unit × quantity as a rational); `SettlementRule` (currency, `CurrencyQuantum` 0–4 places, an explicit `RoundingMode`) is the only way an amount is rounded; `SettledAmount` keeps the computed value, the settled amount and the residual apart; a header is `Arithmetic::sumSettled` of settled lines, never a re-rounded aggregate. `FinancialResult` is COMPLETE / PARTIAL / UNKNOWN and cannot be built inconsistently, so unknown never reads as zero and partial never as complete. The shared `MoneyCast` is **not** used and not changed: it loads a four-place amount in a two-place currency with `RoundingNecessaryException` (pinned by a characterisation test); it has no model callers today, and replacing it needs its own review.
- **`Support/Pricing` (PR0).** `ResolveSalesPrice` checks the Party (a prospect needs no CustomerAccount), verifies the source through a `PricingSourceAdapter` (direct Item and service Item, Assembly work from an ACTIVE version, governed manual scope), picks one `PriceCandidate` by explicit rank (no candidate, a currency mismatch or two equal best ranks refuse; never the cheapest) and settles the line. Cost comes read-only from `QueryMaterialCost` / the Estimating roll-up and is kept as certainty in its own currency; it never sets a price. Assembly work needs the existing `estimating.internal_cost.view` duty (a cost-blind adapter is a later slice). Alternate units and packages, the pricing master, FX, tax, discounts and controls are not here.
- **`Support/Kernel` (AU2/AU5 interfaces).** `SalesActorContext` (explicit actor and company, identity only — not a permission verdict), the `OperationReceiptPort` interface and `OperationIntent::hash` (the existing CRM canonical-hash convention). No receipt table or implementation.

**Pending correction (PX1, [0142](../../../../docs/decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md)):** `Arithmetic::quantity` applies one nine-place scale to every source and the kernel does not yet check Item increments; it is to be replaced by source-specific quantity contracts. Until then the kernel must not be exposed.

A guarded Action must check its own duty and scope before calling the kernel and project the result with `PricingResult::forReader`.

## Non-obvious

- **Access.** `SalesAccess` is the one guard: the capability *and* "not limited to my units". Own/assigned/unit visibility is not built, so a limited person is **refused (403)**, never shown a filtered list (ADR 0106). Ownership grants nothing.
- **A closed opportunity is immutable** until reopened (database trigger); `sales_events` and `sales_notes` are append-only; stages and leads cannot be deleted.
- **Unknown value stays unknown**: `expected_amount` and `expected_currency_id` are both set or both null.
- **Numbering** (`LEAD`, `OPPORTUNITY`) is allocated when the record is created; the format is managed with `sales.numbering.manage`.
- Parties' candidate search lets Sales users (`sales.lead.manage`, `sales.opportunity.manage`) ask whether an identity already exists.

## Related

- [Phase 4 plan](../../../../docs/phases/phase-4-sales-quotations-and-handover.md) · [R1 closure](../../../../docs/phases/phase-4-r1-decision-matrix.md) · [Parties](../Parties/README.md)
