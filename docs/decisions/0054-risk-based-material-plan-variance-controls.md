# 0054 — Audit every adopted material variance; escalate by configured risk

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 3 material resolution, quotation revisions, material-package selection,
  approval workflows, Phase 5 actual-consumption variance

## Context

Material resolution preserves a calculated standard quantity and lets a user adopt a different
planned quantity. Requiring a written reason for every harmless rounding adjustment creates noise;
allowing every change without explanation hides estimation errors and material risk. Independent
approval is a stronger control again and must not be confused with either permission or a reason.

There is no single industry control. Odoo's flexible-consumption choices reduce to allowed,
allowed with warning and blocked behaviour
([Odoo bill of materials](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/manufacturing/basic_setup/bill_configuration.html)).
Dynamics permits material over-consumption and separately reports quantity/substitution variances
([material registration](https://learn.microsoft.com/en-us/dynamics365/supply-chain/production-control/register-material-consumption-hand-held-device),
[variance sources](https://learn.microsoft.com/en-us/dynamics365/supply-chain/cost-management/common-sources-of-production-variances)).
Oracle material transactions can carry an optional reason code
([Oracle Mobile Supply Chain Applications User's Guide](https://docs.oracle.com/cd/V77972_02/current/acrobat/122mscaug.pdf)).
The useful distinction is therefore the effect—record, explain, approve or block—not the product's
label.

This ERP already has immutable actor/time/reason audit patterns and module-specific maker/checker
and multi-approver workflows. It does not yet have the shared configurable approval engine in Q27.

## Decision

### 1. Control an adopted plan, not every draft keystroke

Users may explore quantities and package alternatives freely in an ephemeral preview or editable
draft. Controls run when the user adopts the material plan and run again when the owning document
is submitted or issued.

Every adopted difference preserves standard and planned quantity independently. The system never
overwrites the calculated standard. Phase 5 records actual consumption as a third fact; approval
of a plan is not approval of an eventual actual variance.

### 2. Use four control effects

| Effect | Consequence |
|---|---|
| `AUDIT_ONLY` | Accept the plan and store immutable variance evidence; no explanation is required. |
| `REASON_REQUIRED` | Accept only after a valid reason code and any policy-required explanation are supplied. |
| `APPROVAL_REQUIRED` | Preserve the proposed plan, but prevent submission/issue until the required independent approval completes. |
| `BLOCKED` | Reject adoption; the technical standard, transaction context or governing policy must be corrected. |

These are effects, not role names. Permission determines who may attempt an adjustment; the
effective policy determines its control; an approval decision is a separate immutable fact.

Before a company configures non-zero tolerances, any non-zero planned-quantity variance defaults
to `REASON_REQUIRED`. There is no invented universal 5% or monetary allowance. A company may
deliberately configure `AUDIT_ONLY` bands for routine low-risk adjustments.

### 3. Resolve a versioned company policy with category/item exceptions

The effective material-variance policy resolves:

```text
item override → nearest item-category ancestor → company default
```

The policy is versioned and effective-dated. An adopted plan snapshots the policy/version and the
facts used to evaluate it. A later policy edit affects future evaluations and never reinterprets
history.

The first policy vocabulary is typed and bounded, not an arbitrary expression language. It may
define separate over-plan and under-plan bands using applicable measures such as:

- absolute quantity in an item-valid UoM;
- percentage difference from the standard quantity;
- absolute or percentage internal-cost impact;
- expected package residual/waste or another explicitly supported controlled consequence;
- an item/category flag requiring a minimum control regardless of numeric tolerance.

When several configured conditions match, the strongest effect wins:

```text
BLOCKED > APPROVAL_REQUIRED > REASON_REQUIRED > AUDIT_ONLY
```

Quantity, cost and waste are not interchangeable units and retain separate thresholds. An
absolute-quantity threshold is valid only in its configured item/UoM context. Zero standard
quantity cannot produce a meaningful percentage; the resolver uses applicable absolute/risk rules
and reports that limitation rather than dividing or silently treating it as zero.

### 4. Treat package choice separately from quantity variance

Selecting a different valid package mix while keeping the same planned quantity normally requires
no reason. The system retains the generated recommendation and the user's final selection so the
choice remains explainable.

A package choice is escalated only when a configured consequence crosses a control band—for
example, materially higher internal cost or residual/waste. Expired, quarantined, reserved,
inaccessible or unavailable stock is invalid input and remains blocked by inventory rules; it is
not made valid by an approval.

### 5. Store one immutable adoption/evaluation fact

Each adopted evaluation records or snapshots at least:

- standard quantity, planned quantity, UoM, signed/absolute delta and percentage where defined;
- technical-standard and calculation provenance;
- recommendation strategy/version, generated package proposal and final selection where present;
- internal-cost and residual/waste impact used by policy;
- effective policy/version, every matched condition and final control effect;
- adopting actor and time;
- reason code and written explanation when required;
- approval request/decision references when approval is required;
- owning draft/revision identity.

Repeated adoption creates a new fact rather than rewriting the previous evaluation. Ordinary
typing history is not an audit trail.

### 6. Material changes invalidate approval

After approval, a change to standard/planned quantity, item/UoM, technical-standard version,
selected package lines, evaluated cost/waste consequence or another snapshotted routing input
requires re-evaluation. If the new result still requires approval, it creates a new approval
request/revision; an old decision never floats onto changed facts.

Editorial changes that do not alter policy inputs need not invalidate approval. The document-
owning module defines and tests that semantic comparison.

### 7. Approval is capability-dependent and cannot degrade silently

`APPROVAL_REQUIRED` uses the shared approval contract decided under Q27, including policy
snapshot, eligible authority, separation of duties, distinct approver counts and immutable
decisions. Until that capability is implemented, the UI must not offer an approval-requiring band
as usable configuration. If historical/imported configuration nevertheless resolves to approval,
submission/issue blocks; it never falls back to a reason or self-approval.

Q25 decides the independent permissions for adjusting plans, viewing sensitive cost, approving
exceptions and viewing history. A user allowed to adjust a plan is not thereby allowed to approve
it.

### 8. Use variance history to improve standards

Reporting must distinguish one-off justified exceptions from repeated similar deviations. A
recurring reason for the same item/category/technical standard is a signal to review formulation,
application rate, wastage or packaging master data; repeated overrides must not become an
invisible substitute for correcting the standard.

## Example

A standard calculation requires 6.5 L:

- 6.7 L is inside the company's configured routine band: adopt and audit only;
- 7.5 L crosses the explanation band: require “porous surface” plus the user's note;
- a larger change crosses the configured cost-approval band: keep the draft but block issue until
  an eligible independent approver decides it;
- 10 L crosses a hard limit: block it and require correction or a separately designed controlled
  exception.

Choosing one 5 L plus two 1 L packs instead of a recommended remnant-plus-small-packs combination
does not itself require a reason if both cover the same 7 L and no cost/waste control is crossed.

## Consequences

- Small companies can keep routine adjustments light while retaining an audit trail.
- Larger/regulated companies can add reason, approval and hard-stop bands without different
  quantity semantics.
- Company policy has safe category/item exceptions and historical version evidence.
- Users are not trained to enter meaningless reasons for harmless draft edits.
- Approval configuration depends on the real shared workflow; it cannot be simulated with a
  permission or text field.
- Implementation needs deterministic policy evaluation, immutable adoption facts and semantic
  approval invalidation tests.

## Alternatives considered

**Require a reason for every non-zero difference** — retained as the safe unconfigured default,
but rejected as the only company policy because it creates low-value audit noise.

**Audit only, regardless of impact** — rejected because high-cost, regulated or extreme changes
may need explanation, independent authority or prohibition.

**Require approval for every difference** — rejected as disproportionate and operationally slow.

**Apply controls while the user types** — rejected; exploration is not a business decision and
would create unusable history.

**Treat any non-recommended package mix as an override** — rejected; the recommendation is
advisory and several equally valid physical allocations can cover one plan.

**Use one percentage threshold for every material** — rejected; risk and meaningful units differ,
and a universal percentage would be arbitrary.

## Related

- [Material resolution](../domain/material-resolution.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Approval workflows](../architecture/approval-workflows.md)
- [Authorisation](../architecture/authorization.md)
- [ADR 0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
- [ADR 0053](0053-explainable-inventory-package-selection-presets.md)
- [Q25 and Q27](../OPEN-QUESTIONS.md)
