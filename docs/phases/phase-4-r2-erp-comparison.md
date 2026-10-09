[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R2 — Primary ERP comparison

**Accessed:** 2026-10-09. **State:** initial evidence for owner discussion, not accepted decisions.
Question: how can one quotation represent goods, services and estimated work, preserve the offered
revision, and distinguish included, optional and competing scope without duplicate totals?
R1 identity/forecast research and accepted estimating/units research are reused rather than repeated.

## Compared approaches

| Product and primary source | Observed behaviour | Application and caution for this ERP |
|---|---|---|
| Odoo 19 — [Create quotations](https://www.odoo.com/documentation/19.0/applications/sales/sales/sales_quotations/create_quotations.html) | Product quantities, invoice/delivery context, sections and notes organise an offer. | Ordinary product entry and optional presentation are useful. Product rows alone do not describe our resolved work estimate; invoice address is not financial readiness. |
| Odoo 19 — [Optional products](https://www.odoo.com/documentation/19.0/applications/sales/sales/sales_quotations/optional_products.html) | Optional sections initially give products zero quantity, excluding them from the total; portal users can add quantities. | Optional scope should be visibly excluded until chosen. We should retain its offered quantity and explicit inclusion status rather than overload zero; customer selection must append evidence, not mutate issued scope. |
| ERPNext — [Quotation](https://docs.frappe.io/erpnext/quotation) | Alternative items are excluded from quotation totals and can be selected when creating an order. | A chosen base and separately priced alternatives are understandable. Mapping alternatives directly to an order does not settle our immutable acceptance or partial-scope contract. |
| ERPNext — [Selling in different UOM](https://docs.frappe.io/erpnext/Selling-in-different-UOM) | Sales quantity/unit and item conversion distinguish the selling measure from stock measure. | Reuse our item conversion and packaging concepts; do not invent a universal carton-to-each conversion or copy its price-list settings as our pricing policy. |
| ERPNext — [Product Bundle](https://docs.frappe.io/erpnext/product-bundle) | A non-stock commercial parent has stocked child items represented separately in transaction packing lists. | Selling one scope and tracking several requirements can remain separate. A bundle is not automatically our formulation, labour/plant estimate, manufactured output or physical reservation. |
| Dynamics 365 Sales — [Sales transactions](https://learn.microsoft.com/en-us/dynamics365/sales/sales-transactions) | Draft quotations become read-only when activated; revision creates a new draft with an incremented revision identifier. | Distinguish editable preparation from identifiable offered evidence. Revisions must not be counted as independent deals; our supersession rules remain R5. |
| Dynamics 365 Sales — [Add products](https://learn.microsoft.com/en-us/dynamics365/sales/add-product-quote-order-invoice) | Catalogue/bundle and ad hoc write-in lines share quotation entry; write-ins require explicit unit pricing. | Simple manual scope is a credible alternative to compulsory catalogue maintenance. Its permission and price controls must follow our shared policy; changing the Assembly-only contract requires owner approval. |

Odoo's official [quotation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/sales_quotations/create_quotations.rst)
and [optional-products source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/sales_quotations/optional_products.rst)
were also inspected to verify the version-specific section behaviour. These sources establish
product behaviour, not a claim that our corresponding engine exists.

## Convergence and differences

**Common pattern:** commercial offer rows identify the scope, quantity and price; supporting
requirements can differ from customer presentation. Ordinary selling need not expose costing
complexity. Options need explicit total treatment. Negotiation requires identifiable evidence.

**Meaningful differences:** Odoo uses optional zero quantities and portal interaction; ERPNext
marks alternatives and selects them downstream; Dynamics supplies explicit revision and write-in
concepts. None of these sources establishes our accepted independent-family/exclusive-family
contract, material variance controls or SELECTED_OFFERS forecasting treatment. Those stay governed
by our ADRs, not vendor vocabulary.

## Alternatives, failure modes and synthesis

| Alternative | Benefit | Failure mode / consequence |
|---|---|---|
| Assembly required for every sellable scope | Preserves ADR 0008 and uniform estimate references | Extra maintenance for plain goods or manual services; hidden wrappers still need governed versions and provenance. |
| Shared commercial line with optional Item/Assembly/manual source | Simple entry with advanced estimating where needed | Changes accepted Assembly-only semantics; needs a superseding decision and defined costing/readiness states. |
| Mirror estimate components as customer lines | Detailed transparency | Double-counts a priced parent plus children; leaks internal rates and mistakes inputs for accepted deliverables. |
| Flat rows with optional sections | Low setup cost; industry-neutral work phases | Sections must not silently become indivisible acceptance units. Deep hierarchy/configurators add complexity. |
| Explicit offered quantity plus inclusion/choice status | Preserves what was offered and keeps totals explainable | Requires a validated selection contract; quantity zero cannot be its substitute. |
| Treat every alternative as another independent quotation | Simple document separation | Overstates potential unless family competition is explicit; duplicates may survive acceptance/handover. |

**Recommendation for owner review:** one shared commercial scope contract, optional presentation,
explicit inclusion/choice rules and independently preserved estimate evidence. Permit simpler line
sources only through a conscious revision of the Assembly-only decision. Do not copy portal edits,
order transitions, stock movements or vendor price engines into Phase 4 by implication.

## Evidence limits

This is a bounded comparison of three mature systems, not an evaluation of every edition,
configuration or extension. No source above resolves our detailed partial acceptance, dependency
satisfaction, tax, forecast overlap or job multiplicity. Those questions have named R3–R9 owners
in the [consolidated recommendations](phase-4-r2-quotation-structure.md).

## Related

- [R2 decision matrix](phase-4-r2-quotation-structure.md) · [Actual capability review](phase-4-r2-existing-capabilities.md)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Research rules](../architecture/research-and-design.md)
