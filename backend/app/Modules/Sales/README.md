# Sales

The CRM foundation of Phase 4: optional leads, opportunities with one accountable owner, configurable OPEN stages, stable outcomes and the history behind them.
Quotations, pricing, tax, acceptance and job handover are **not** here yet — they wait for their accepted decisions (R2–R8).

**Governed by:** [ADR 0098](../../../../docs/decisions/0098-hybrid-sales-opportunity-requirement.md) (optional lead, direct opportunity),
[0105](../../../../docs/decisions/0105-support-generic-party-identity-and-conversion.md) (identity reuse and conversion),
[0106](../../../../docs/decisions/0106-separate-sales-ownership-and-access.md) (ownership is not access),
[0107](../../../../docs/decisions/0107-separate-opportunity-stages-and-outcomes.md) (stages and outcomes),
[0108](../../../../docs/decisions/0108-expose-a-progressive-basic-crm.md) (basic CRM). Domain: [sales-pipeline.md](../../../../docs/domain/sales-pipeline.md).

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

## Non-obvious

- **Access.** `SalesAccess` is the one guard: the capability *and* "not limited to my units". Own/assigned/unit visibility is not built, so a limited person is **refused (403)**, never shown a filtered list (ADR 0106). Ownership grants nothing.
- **A closed opportunity is immutable** until reopened (database trigger); `sales_events` and `sales_notes` are append-only; stages and leads cannot be deleted.
- **Unknown value stays unknown**: `expected_amount` and `expected_currency_id` are both set or both null.
- **Numbering** (`LEAD`, `OPPORTUNITY`) is allocated when the record is created; the format is managed with `sales.numbering.manage`.
- Parties' candidate search lets Sales users (`sales.lead.manage`, `sales.opportunity.manage`) ask whether an identity already exists.

## Related

- [Phase 4 plan](../../../../docs/phases/phase-4-sales-quotations-and-handover.md) · [R1 closure](../../../../docs/phases/phase-4-r1-decision-matrix.md) · [Parties](../Parties/README.md)
