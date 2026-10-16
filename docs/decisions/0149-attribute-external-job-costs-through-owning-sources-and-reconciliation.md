[← Decision index](README.md)

# 0149 — Attribute external Job costs through owning sources and reconciliation

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase 5 E1–E8/Q54; Purchasing, Job Execution, Inventory and future Finance
- **Authority:** owner approval of business decisions and bounded architecture, not implementation

## Context

Small businesses need truthful incurred external expenses without mandatory POs. Larger workflows
need partial supplier performance, shared Job attribution and later invoice reconciliation. Separate
ledgers or acceptance systems would duplicate economic contributions. Goods receipt posts physical
Inventory and cannot truthfully substitute for service acceptance.

## Decision

Extend 0146's logical source-attribution, recognition and correction principles through appropriate
owning-domain adapters. Retain distinct economic identities and classify by substance, not entry form.
One effective economic contribution appears once across Job/activity/category and later-source views.

Allow evidenced direct incurred external expenses without mandatory POs where policy permits.
When documents are unavailable, an authorised accountable internal declaration retains context,
amount/currency, incurred basis, scope, reason and available counterparty information. Never invent
supplier identity/documents. Distinguish declared evidence from externally verified invoice/payment
or final accounting expense. No bypass of required procurement or service acceptance.

Purchasing owns reusable authoritative supplier-service acceptance; Job technical evidence feeds
it without another acceptance. Support bounded fixed services, defined units, partial deliverables
and hire periods. Supplier acceptance is separate from customer fulfilment, invoice and payment;
never manufacture Inventory receipts for services.

Recognise incurred cost before invoicing where accepted performance and reliable authorised valuation
exist. Supported performance with missing/uncertain value retains incomplete valuation. Agreement,
invoice or payment alone does not establish performance. Later invoices explicitly confirm, replace
or adjust prior contribution through linked reconciliation/correction/dispute, never silent overwrite.

Conserve quantities and amounts across line/portion allocations to Jobs and an explicit unallocated
company remainder, including partial delivery/correction/cancellation. Preserve issued history,
lineage, exact retries, compatible locks/transactions and current authority. Stocked actuals remain
Inventory-consumption based under 0148, not additive PO/invoice expense.

Include simple fixed/day/unit hire and evidence-based subcontract stages/partial acceptance as
initial candidates. Chargeable hire differs from productive usage or presence. Preserve accepted,
disputed, rejected, remaining and terminated portions; invoice/progress percentage is not fulfilment.
Defer advanced fleet/metering, retention certification, complex hierarchies and manufacturing subcontracting.

Payments/deposits/GL representations do not add cost again. Stable identities and correction history
govern reconciliation, not amount/date matching. Owning domains retain FX/tax/invoice/period/accounting
authority. Reuse R7/R9/0145–0148; independent procurement, acceptance, technical evidence, recognition,
correction and cost-read duties. Holds/termination/closeout preserve obligations; required unsupported
effects refuse safely. No later Finance integration is claimed delivered.

## Consequences

- **Positive:** SIMPLE evidence scales into managed supplier workflows without another cost ledger.
- **Negative:** source adapters, partial conservation and later reconciliation require explicit delivery.
- **Requires:** [bounded contract](../domain/job-external-cost-attribution.md) and affected writer/
  correction/closeout integrations before activation. No code/grants/migrations authorised.

## Alternatives considered

**Universal PO or invoice-only costing** — burdens small expenses or conceals evidenced incurred work.
**Separate external ledgers/acceptance systems** — duplicates work and correction obligations.
**Payment or manual progress proves performance** — confuses financial and technical evidence.
**Invoice adds cost again / silently replaces history** — duplicates expense or destroys lineage.

## Research basis

Reuse [initial assessment](../phases/phase-5-purchasing-external-research.md) and
[E1–E8 evidence/options](../phases/phase-5-purchasing-external-resolution.md), accessed2026-10-16.
Business Central/ERPNext distinguish purchase stages; SAP service-entry evidence separates performance
from invoicing; Dynamics invoice matching reverses/replaces earlier actuals. Products differ in
PO/approval/accounting assumptions. This project retains source ownership and reconciled identities,
without mandatory enterprise packaging. Existing Purchasing Action inspection confirms physical
receipt/stock short-close boundaries, not delivered service acceptance. Owner's declaration and
first-scope clarifications are authoritative business choices, not copied vendor defaults.

## Related

- [External attribution contract](../domain/job-external-cost-attribution.md)
- [0146](0146-recognise-flexible-labour-evidence-without-fabricating-usage.md)
- [0148](0148-bound-initial-job-material-paths-and-require-correction-delivery.md)
- [Phase 5](../phases/phase-5-job-execution.md)
