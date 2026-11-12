# 0187 — Phase 4 closure decisions D1-D16

- **Status:** accepted (owner decisions 2026-11-12)
- **Date:** 2026-11-12
- **Affects:** Phase 4 closure; Sales, Inventory, Purchasing, Approvals, access administration, UI verification; supplements [0170](0170-accept-the-missing-exact-sha-ci-evidence-for-the-demo-operator-bootstrap.md), [0185](0185-configure-company-business-values-and-fix-technical-invariants.md), [0165](0165-reconcile-purchase-order-fx-with-the-governed-rate.md)

## Context

The owner reviewed the open Phase 4 closure questions on 2026-11-12 and decided them as a pack. This ADR records the decisions in one place so later work can cite them. It authorises no
production release, no permission activation and no code beyond what each item names. D10 and D11 are recorded as status/owner notes on [0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md)
and [0158](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md).

## Decision

- **D1 - Historical exact-SHA CI exceptions.** Allowed only when the current integrated head has passing exact-head CI, the affected security regressions pass, and an independent review
  confirms the final behaviour. [0170](0170-accept-the-missing-exact-sha-ci-evidence-for-the-demo-operator-bootstrap.md) is an exceptional precedent, not a general waiver. An earlier failed run
  is not to be labelled unrelated without evidence.
- **D2 - Two Inventory Actions.** Fix both to transaction-time authority, including suspended, revoked, replayed and concurrent cases. The authorisation matrix is corrected only after the
  fixes pass.
- **D3 - Early-check-only Actions.** Open a separate post-Phase-4 issue to inventory Actions that check authority only early. Priority: financial posting, inventory mutation, approvals, access
  administration, irreversible operations. Confirmed high-risk defects are fixed before those workflows reach production. Deferral is not security clearance.
- **D4 - Customer acceptance.** Acceptance needs verified consent and immutable evidence. Acceptance authority is independent from the permission to record evidence. By default the same
  authorised employee may record the evidence and register genuine written customer acceptance. An optional company-level policy may require independent acceptance verification (default OFF;
  enforced server-side when ON). No configuration may bypass mandatory maker/checker for internal approvals. Reconciliation with 0185: see the
  [design](../phases/phase-4-acceptance-verification-policy-design.md) (proposed, needs owner authorisation).
- **D5 - #107.** Accepted only after independent review and proof on fresh and already-migrated databases, using a forward correction where necessary.
- **D6 / D7 - Splits of #76 and #77.** Split as the owner directed; the QA5 review goes first.
- **D8 - FX.** [0165](0165-reconcile-purchase-order-fx-with-the-governed-rate.md) is accepted. Phase 4 may close with the fixed 0.5% tolerance and 7-day rate age as documented INTERIM
  behaviour. Per-company FX governance is separately authorised post-Phase-4 work; no permanent global financial constants.
- **D9 - Hardcoded-value audit.** Read-only audit of hard-coded business values.
- **D12 - Customer messages.** Filters for customer messages as the owner specified.
- **D13 - Minimum UI verification.** Keyboard and focus, dialog/sheet focus, light and dark, 390px and 320px, basic screen-reader pass of the critical quotation/approval/acceptance
  workflows, and the #102 wording. Comprehensive accessibility and Firefox/Safari checks are pre-production release requirements. No unverified test is recorded as passed. Real discount
  bands are tracked in #105.
- **D14 - Starter Roles.** Design only: company-configurable templates built from ACTIVE duties only; STAGED duties excluded; no automatic sensitive or company-wide grants.
- **D15 - QA4 holder recipe.** Separate from activation. Phase 4 closes with sensitive duties STAGED and ungranted; a reviewable recipe and an activation/backout procedure are prepared.
- **D16 - No production release of Phase 4 yet.**

**Boundary.** Quotation issuance, verified acceptance and inactive handover preparation may close after the gates above. The Sales-to-Jobs release/receiver stays in Phase 5.

## Consequences

- **Positive:** one citable record; closure scope and its limits are explicit; deferrals are named, not implied clearances.
- **Negative:** several items (D3, D8, D14) leave known interim behaviour or open follow-up work after Phase 4.
- **Requires:** issues for D3, D8 follow-up, D6/D7 splits and D9; the D4 policy needs separate owner authorisation before any code.

## Alternatives considered

**Treat 0170 as a general CI waiver** - rejected (D1). **Close Phase 4 with Inventory early-check defects unfixed** - rejected for the two named Actions (D2); the rest is deferred with priority (D3).
**Release Phase 4 now** - rejected (D16).

## Related

[0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md) · [0158](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md) · [0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md) · [0170](0170-accept-the-missing-exact-sha-ci-evidence-for-the-demo-operator-bootstrap.md) · [0185](0185-configure-company-business-values-and-fix-technical-invariants.md)
