[← Decisions](README.md)

# 0161 — Switchable quotation demo presets and local-preview activation

- **Status:** accepted (owner-approved defaults; safest-default choices below may be refined by the owner)
- **Date:** 2026-11-06
- **Affects:** `SalesDevPreview`, Administration (preset Action, operator bootstrap), `sales:demo-preset`, `QuotationAccessDemoSeeder`, [quotation access demo](../domain/quotation-access-demo.md); supplements [0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md), [0156](0156-quotation-demo-access-and-activation-boundaries.md), [0159](0159-patch-only-approved-quotation-access-for-the-main-demo-user.md)

## Context

The owner wants to switch the main demo login between named permission sets and see each rule on the real screens. 0159's patch manages only five access duties (view, prepare, assign, delegate, revoke) and
the local preview activated only view, prepare, issue and documents, so the access family, the pricing concessions and the approver duty could not be handed out at all. The owner approved: the
approver duty and the discount/price-override duties may be ACTIVE in the local preview (not in the shipped manifest); delegation of approval stays off; approvals do not expire.

## Decision

1. **Preview activation.** `SalesDevPreview::manifest()` also activates assign, access.delegate, access.revoke, discount, price.override, commercial.view and approve, under the unchanged local-only
   switch. `SalesDevPreview::all()` (what `sales:dev-grant-quotation` hands out) is unchanged; activation grants nobody anything. The shipped manifest keeps every duty STAGED. Repair, withdraw, send
   and delivery-send are not activated.
2. **Presets in two layers** (`QuotationDemoPresets`). *Access* roles (quotation-only: viewer, preparer, delegate, access manager, governor) with a scope per assignment are applied to the main login
   through 0159's `SyncMemberAccess::patchQuotationDemo`, acting as the synthetic operator. *Workflow add-ons* (issue, discount, price override, commercial view, approve) are direct grants kept in step with the
   preset, exactly like the existing `sales:dev-grant-quotation`, local preview only, audited. The patch is not widened: it still manages only the five access duties and never issue or repair.
3. **Operator first-holder.** 0155 is implemented as `BootstrapDemoAccessOperator` with an append-only receipt table. The operator's administration rights (`administration.access.assign`,
   `administration.role.manage`) are given first by the demo administrator through the ordinary Actions; the bootstrap then adds exactly the five approved duties. It is a separately reviewable commit and still
   needs the exact-SHA review and CI that 0155 requires before merging.
4. **Personas are new logins.** The existing CRM personas keep their CRM roles (an ordinary full replacement by the operator would be refused, correctly). New `demo-quote-*` logins carry the
   preset roles and scopes through the ordinary `SyncMemberAccess`.
5. **Fictitious approval routing.** The Approvals engine routes a quotation on the kind of concession and the currency only, not on a discount percentage. The demo adds a clearly labelled policy:
   a price override (alone or with a discount) needs a sales lead and then a director; a discount alone keeps the single-step policy. The owner's real discount thresholds replace this once supplied and
   need a routed percentage fact first.

## Consequences

- **Positive:** the owner can flip eleven presets with one command and see default-deny, OWN, MY_UNITS, shares, assignment, concessions, issue and approval behave on the real screens.
- **Negative:** workflow add-ons for the main login are ALL-scoped direct grants (like the existing preview grants), not record-scoped; the preview now activates more duties for every local developer who has
  the switch on (still granting nobody anything).
- **Requires:** the exact-SHA review of the bootstrap; tests for the preset matrix, idempotence, self-change refusal, non-demo refusal and operator without issue/repair (delivered with this ADR).

## Alternatives considered

**Widen the 0159 patch to the workflow duties** — rejected: 0159 says never issue/repair, and the operator would then have to hold issue; the local-preview direct grant is the existing, owner-approved mechanism.

**Seed the operator through raw `givePermissionTo` in the seeder** — rejected: it is the unguarded first-holder path 0155 exists to replace with a bounded, audited Action.

**Re-assign the existing CRM personas** — rejected: the operator cannot retain CRM rights it does not hold; weakening that guard is not allowed.

**Percentage thresholds in the demo policy** — not possible without a new routed fact; recorded as the gap above instead of faking it.

## Research basis

Local technical/demo packaging decision on top of accepted access and approvals designs; no new ERP business behaviour.

## Related

- [Quotation access demo](../domain/quotation-access-demo.md)
- [0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md), [0156](0156-quotation-demo-access-and-activation-boundaries.md), [0159](0159-patch-only-approved-quotation-access-for-the-main-demo-user.md), [0158](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md)
