[← Architecture](README.md)

# User-interface delivery

The React SPA is an operational ERP interface, not a collection of CRUD forms. Each phase ships
the screens needed to perform and understand the backend workflows it introduces. A phase is not
UI-complete merely because its endpoints have generated hooks.

## Design gate before a workflow is built

For each workflow, write a short interaction specification in its domain document before coding:

1. Identify the primary users, decisions and frequent tasks; distinguish daily operations from
   setup, exception and audit work.
2. Inventory the complete lifecycle and permission matrix. State which actions are available in
   each status, which are irreversible and what confirmation/reason is required.
3. Define list, create/edit and detail/workspace information architecture, including saved or
   URL-backed filters, empty/loading/error states and the mobile alternative to a dense table.
4. Prototype the highest-risk flow using realistic data. Review keyboard operation, focus order,
   validation recovery, decimal/date presentation, destructive-action prevention and narrow-screen
   use before settling API additions.
5. Reuse `core-ui` patterns where they fit, but perform a consistency/accessibility audit instead
   of copying accidental limitations from the first screen.
6. Record the agreed screen inventory and acceptance scenarios in the phase/domain docs, then
   implement through generated API hooks and test the critical user journey.

The baseline is WCAG 2.2 AA. In particular, errors must be identified in text and associated with
their controls; keyboard focus must remain visible and unobscured; consequential financial/data
submissions need review, confirmation or a reversible correction path; and pointer targets must be
at least 24 by 24 CSS pixels or have the required separation. Frequent mobile actions should aim
for the stronger 44 by 44 target. References:
[WCAG 2.2](https://www.w3.org/TR/WCAG22/),
[target-size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum) and
[GOV.UK interaction patterns](https://design-system.service.gov.uk/patterns/).

## Shared ERP interaction rules

- Lists answer an operational question, not merely expose a table. Show useful defaults and totals,
  preserve filters in the URL and provide a card/detail alternative on narrow screens.
- Document detail is a workspace: identity and status first, lines and totals together, then audit
  history and secondary metadata. Available actions derive from server state and permission.
- Draft line entry must support keyboard-efficient repeated work. Do not hide validation until the
  final document submission, and never calculate money with JavaScript floating point.
- Issuing, posting, approving, reversing, cancelling, short-closing and policy changes use named
  actions. Explain consequences before irreversible actions and show the recorded reason/history
  afterwards.
- Reference selectors search by human identity (code and name); raw numeric IDs are an explicitly
  documented temporary fallback, not a finished interaction.
- Dashboards and summaries must state their quantity/value basis and freshness. “On hand”,
  “available”, “on order”, “QA held” and “shortage” are different facts and must not be collapsed
  into a single ambiguous stock number.

## Screen ownership by phase

This is a durable allocation, not a complete speculative design. The detailed interaction
specification is written when each phase starts.

| Phase | Screen families owned |
|---|---|
| 0 — Foundation | Authentication, application shell, account/company context and foundational administration needed by later workflows |
| 1 — Master data | Items/categories, parties/customer/supplier accounts, currencies/rates, UoMs and chart-of-account maintenance; screens may land with the first consuming phase but remain owned by their domain |
| 2 — Purchasing & stock | Supplier sourcing; purchasing settings; purchase orders; goods receipts/reversals; QA dispositions; warehouses/locations; inventory overview; stock ledger; physical counts; cutover; negative-stock and recalculation exceptions |
| 3 — Estimating | Formulations/versions, application rates, assemblies, cost roll-up and estimate-building workspaces |
| 4 — Sales | Leads, opportunities, quotations/revisions, customer-facing document output and sales-owned correction visibility |
| 5 — Job execution | Jobs, work orders, material issue/return, time entry and estimate-versus-actual operational views |
| 6 — Finance | Supplier invoices/matching/payment, customer invoicing/receipts/allocation, journals, periods, tax, ledgers, ageing and finance-owned correction resolution |
| 7 — Mobile & integrations | Field-optimised navigation, barcode/camera flows, offline/retry states, notifications and integration administration |

Cross-module journeys should feel continuous, but each state-changing screen calls the owning
module's API. A later phase extends an earlier workspace with links or owned facts; it does not
move the earlier workflow into the later phase.

## Phase 2 completion boundary

Before Phase 2 is complete, research and agree the interaction specification and then deliver:

- an inventory overview that explains on-hand, available, QA-held, shortage and on-order quantity,
  supports item/warehouse/location/tracking drill-down and links to the immutable ledger;
- purchase-order list, draft create/edit and detail workspaces with issue, cancel and line
  short-close actions;
- goods-receipt list, create/detail, post and reversal workflows, optimised for repeated line and
  tracking entry;
- receipt-line QA disposition workflow, including unresolved quantity and disposition history;
- purchasing and inventory policy/settings screens with permission-aware reasons and audit context;
- a UX consistency/accessibility pass over existing item, supplier, warehouse, ledger, count,
  cutover, negative-stock and recalculation screens.

Supplier invoicing/matching remains Phase 6; job material issue remains Phase 5. Phase 2 screens
must show those boundaries honestly rather than offering actions whose owning documents do not yet
exist.

## Related

- [API conventions](api-conventions.md)
- [Code structure](code-structure.md)
- [Extensibility](extensibility.md)
- [Roadmap](../phases/README.md)
- [Phase 2](../phases/phase-2-purchasing-stock.md)
- [ADR 0002](../decisions/0002-api-first-react-spa.md) ·
  [ADR 0006](../decisions/0006-generated-api-client.md) ·
  [ADR 0015](../decisions/0015-capacitor-for-mobile.md)
