# 0057 — Use stable material-resolution capabilities with dynamic company assignment

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 3 Material Resolution / Assembly Preview, authorisation, approval workflows

## Context

Q25 asks who may run Material Resolution, see inventory or internal cost, change the plan, adopt a
package mix, request or approve a controlled variance and inspect history. The answer must fit a
two-person company and a company with departments, authority limits and independent approvers.

Those companies do not need different application permissions. They need the same stable business
capabilities combined with different roles, user assignments, record scopes and approval policy.
A permission answers “may this person perform this kind of action?” It does not answer which
records they may access or prove that a particular transaction was approved.

Industry ERP security follows that separation. Dynamics groups permissions into privileges,
duties and roles, then applies data-security and workflow rules. Oracle describes data access as
the combination of user, job role and security context. Odoo separates additive group/model
rights from record rules and field restrictions.

## Decision

The Estimating module registers these stable first-generation capabilities:

```text
estimating.material_preview.run
estimating.inventory_availability.view
estimating.internal_cost.view
estimating.material_plan.adjust
estimating.package_plan.adopt
estimating.material_variance.request
estimating.material_variance.approve
estimating.material_resolution_history.view
```

Companies dynamically bundle them into roles and assign roles to users. Additive direct-to-user
grants remain available for exceptional or temporary duties. Application code checks capability
names, never role names. Companies cannot create executable permission names that no application
boundary knows how to enforce.

Permission names never contain a user, department, warehouse, record, amount or approval count.
Those are assignment, data-scope or policy facts.

### Capability and field contract

| Capability | Grants | Does not grant |
|---|---|---|
| `material_preview.run` | Calculate and view technical standard/planned quantities and non-sensitive provenance | Inventory balances, package/container identity, internal cost or a persisted adoption |
| `inventory_availability.view` | Add inventory/package availability and alternatives within the actor's effective record scope | Internal valuation/cost, reservation or stock movement |
| `internal_cost.view` | Internal unit/extended cost, valuation evidence and cost/waste consequences | Selling price, margin, supplier offers or permission to alter the plan |
| `material_plan.adjust` | Change planned quantity in a draft and trigger ADR 0054 re-evaluation | Adopt a package plan or bypass a reason/approval/block |
| `package_plan.adopt` | Select an otherwise-valid inventory proposal or alternative for the draft | Adjust technical/planned quantity, reserve stock or bypass ADR 0054 |
| `material_variance.request` | Submit the current immutable revision/evaluation for required approval | Approve it or edit it after submission |
| `material_variance.approve` | Decide an eligible pending ADR 0054 approval | Self-approve/conflicting duty, approve outside scope/authority or bypass `BLOCKED` |
| `material_resolution_history.view` | View immutable adoption/evaluation/decision history | Sensitive cost or availability fields lacking their own capabilities |

API resources omit or mask cost and availability fields when their capability is absent. The same
field rules apply to show, history, export, search/lookup, approval tasks and notifications. React
visibility is only a usability reflection of server-side enforcement.

Selling-price/margin access and price override belong to Phase 4 under ADR 0055. Supplier offers
belong to Purchasing. Neither is smuggled into a Phase 3 capability.

### Approval behaviour

ADR 0054's resolved policy determines whether adoption is `AUDIT_ONLY`, `REASON_REQUIRED`,
`APPROVAL_REQUIRED` or `BLOCKED`:

- audit/reason paths require the relevant adjust/adopt capability but no approval decision;
- an approval path additionally requires a requester with `material_variance.request` and a
  distinct eligible actor with `material_variance.approve`;
- an approver cannot be the actor whose conflicting edit/adoption/request requires independent
  review;
- a cost-triggered approval must be routed to an approver who also has `internal_cost.view`;
- `BLOCKED` has no permission bypass.

A small company can grant one Owner/Estimator role all ordinary capabilities and configure normal
bands as audit-only or reason-required, so one person completes the flow without ceremonial
approval. If that company deliberately configures `APPROVAL_REQUIRED`, a genuinely distinct
eligible approver is required; the system must not silently weaken the policy because headcount is
small.

### Scope and delegated administration

The first Phase 3 implementation has company-scoped role/direct assignments because that is the
scope the current Spatie Teams integration can enforce. It must not claim department isolation.
Q26 will add reusable own/assigned/organisation-unit/company/task scopes without renaming these
capabilities.

Future department heads may delegate roles or permissions only inside organisation units they
administer and only from an explicitly delegable subset. They may never grant more authority than
their own bounded administration mandate, grant across companies or silently grant protected
tenant/finance administration. Grant/change/removal, reason, actor and optional expiry are audited.

Routine cross-department work uses scoped role membership, assigned-record access or temporary
approval-task visibility. It does not create one role per collaborating pair. “Write-only” is not
a general access level: create/update is combined with the minimum read access required to perform
and verify the work safely.

## Consequences

- **Positive:** Small and large companies use the same tested enforcement points while configuring
  different roles and approval strictness.
- **Positive:** Cost, availability, action authority and approval are independently grantable.
- **Positive:** Department and record scopes can be added later without renaming permissions or
  rewriting Material Resolution.
- **Negative:** The first implementation cannot honestly provide department-limited availability;
  companies either grant company-scoped availability or withhold it until Q26 is implemented.
- **Negative:** More precise capabilities require a role-administration UI and effective-access
  explanation so configuration remains understandable.
- **Requires:** Module-owned registration, backend Action/policy checks, field filtering,
  authenticated-user capability exposure and allowed/denied/sensitive-field tests.
- **Requires:** Q26 before delegated departmental administration and Q27 before a reusable
  configurable multi-stage approval engine.

## Alternatives considered

**Hardcoded Small Company and Enterprise roles** — rejected. Roles describe each company's jobs;
application code must protect capabilities rather than role labels.

**One broad `estimating.manage` permission** — rejected. It cannot separate cost visibility,
planning, adoption, history and approval.

**A permission for every department, amount or approval level** — rejected. It creates an
unmaintainable vocabulary and confuses capability with scope/policy.

**Arbitrary company-created permission names** — rejected. A string that no backend boundary
enforces provides an illusion of security.

**Make approval permission sufficient evidence** — rejected. Eligibility is not an immutable
decision on a particular document revision.

**Direct per-user matrices as the normal model** — rejected. They are difficult to audit and,
with additive grants, cannot revoke a permission inherited from a broad role.

## Research basis

- [Dynamics 365 role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security)
- [Dynamics 365 workflow system](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/overview-workflow-system)
- [Oracle data access](https://docs.oracle.com/en/cloud/saas/applications-common/26b/facsa/data-access.html)
- [Oracle Procurement approval rules](https://docs.oracle.com/en/cloud/saas/procurement/25c/oapro/approval-rules.html)
- [Odoo access rights](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html)
- [Odoo security reference](https://www.odoo.com/documentation/18.0/developer/reference/backend/security.html)

## Related

- [ADR index](README.md)
- [ADR 0028 — Dynamic namespaced permissions](0028-dynamic-namespaced-permissions.md)
- [ADR 0054 — Risk-based material variance controls](0054-risk-based-material-plan-variance-controls.md)
- [Authorisation](../architecture/authorization.md)
- [Approval workflows](../architecture/approval-workflows.md)
- [Material resolution](../domain/material-resolution.md)
