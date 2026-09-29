[← Decisions](README.md)

# 0069 — Separate Purchasing commercial visibility from physical receiving

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** Purchase Order and Goods Receipt projections, receipt creation, print views, company roles

## Context

ADR 0068 restricted document reads but left their monetary fields visible. A Receiving Clerk or QA user needs ordered and received quantities, locations, batches and status, not supplier price or valuation. Today the receipt form copies the PO line price and FX details into the create request, so hiding those fields alone would make non-commercial receiving impossible. ADR 0057 explicitly excludes supplier offers from `estimating.internal_cost.view`; Purchasing needs its own capability.

## Decision

- Register `purchasing.commercial-cost.view`. It controls PO line prices, discount/tax/line/base amounts, header totals and FX evidence, and receipt provisional/base unit costs and FX evidence in every list, detail and action response. Omit restricted keys in API resources rather than sending a sentinel that could be mistaken for a real zero. The SPA hides the corresponding columns, detail sections and print fields. Monetary sorting is unavailable without commercial visibility, since ordering documents by a hidden total leaks information.
- `purchasing.purchase-order.create` also carries the minimum commercial read required to draft and edit a priced PO; this preserves the existing Buyer workflow. Other PO actions, receipt actions and document `view` grants do not implicitly grant prices. A company may grant the separate capability to Finance, a commercial receiving clerk or another duty without granting a write action. The editable Buyer starter template includes the explicit visibility grant for clarity, but application code never checks the role name.
- A receipt creator without commercial visibility submits quantity, UoM, location and tracking identity without any price, currency or FX fields. The backend rejects an attempted monetary override from that caller and derives the provisional unit cost, currency and FX evidence from the issued PO snapshot. An authorised commercial caller may continue to supply those fields or omit them to use the same PO defaults. This is a provisional receipt valuation basis, not an invoice-price reconciliation or landed-cost adjustment.
- Posting and reversal still use the stored receipt monetary snapshots internally. Hiding their API projection does not erase accounting facts, weaken existing post guards or change historical values.
- Document `view` remains company-wide pending Q26's record-scope decision. The scope here is Purchasing PO/receipt resources and this receipt-create path; Inventory valuation endpoints, exports and future notifications must be reviewed separately before claiming system-wide cost secrecy.

## Consequences

- Receiving and QA can work with a quantity-only projection and cannot inject an arbitrary cost through the receipt API. Buyer PO creation and existing server-side valuation retain their previous price basis.
- The response schema becomes permission-dependent. Generated client fields are optional; screens must test capabilities and never render blank cost cells as zero.
- Existing read-only commercial users need the new capability assigned before deployment; the configured administrator receives it through normal permission sync. A receiver who must enter a changed supplier cost needs the separate grant, not a broader PO-create role.

## Alternatives considered

**Reuse `estimating.internal_cost.view`** — rejected by ADR 0057's explicit supplier-offer boundary.

**Give every receipt creator commercial visibility** — rejected: physical receiving and QA need quantity and identity without supplier economics.

**Hide prices in React only** — rejected: direct API requests and print projections would still expose them.

**Send `0.0000` or `null` for hidden values** — rejected: a consumer could confuse absent authority with a real zero or incomplete price. Omitted keys match the existing permission-gated preview pattern.

**Require a cost on every receipt POST** — rejected: it forces a non-commercial receiver to know or invent a price. The issued PO already has a frozen purchase-price/FX basis; invoice corrections and landed costs remain later workflows.

## Research basis

Question checked on 2026-09-29: how should physical receiving be kept operational without revealing or inventing supplier economics? Existing [authorisation architecture](../architecture/authorization.md) and [ADR 0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md) establish field-level capability projection. Current official [Dynamics 365 Finance security guidance](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/security-architecture) distinguishes entry-point, field and data access; [Odoo 18 access rights](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html) separates groups and model operations; [Odoo 19 inventory valuation](https://www.odoo.com/documentation/19.0/th/applications/inventory_and_mrp/inventory/inventory_valuation/operations_valuation.html) describes a receipt's source-PO unit price as its valuation basis. The common useful principle is separation of physical receipt work from commercial authority while deriving valuation from a governed source. Vendor workflows differ in invoice matching and cost correction; those are not copied into this Phase 2 receipt slice.

## Related

- [Purchasing](../domain/purchasing.md)
- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [ADR 0068](0068-purchasing-document-read-capabilities.md)
- [ADR 0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
