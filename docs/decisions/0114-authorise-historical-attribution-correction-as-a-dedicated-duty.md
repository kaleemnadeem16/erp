[← Decisions](README.md)

# 0114 — Authorise historical attribution correction as a dedicated duty

- **Status:** accepted — owner-approved Q42, 2026-10-12
- **Date:** 2026-10-12
- **Affects:** Sales historical correction, permission registration/grants and administrator re-sync
- **Relationship:** supplements 0112; resolves its duty/grant gate without changing 0111–0113

## Context

ADR 0112 permits append-only historical responsibility correction, distinct from current assignment,
editing or reopening pursuit. Those ordinary duties must not imply retrospective authority.
The owner reviewed the dedicated permission proposal and approved its boundaries on 2026-10-12.

Sales contributes vocabulary through `PermissionRegistry`; registration creates company/team
Permission rows, not grants. Grants remain additive under ADRs 0028/0089. Existing administrator
re-sync currently grants the whole registry directly to a named user. Role stores name/description
and assigned permissions, not a standing full-access mandate. A role name, command email or current
all-permissions snapshot does not prove eligibility for automatic inclusion of this sensitive duty.

## Decision

Use dedicated `sales.opportunity.attribution.correct` AND independent legitimate Opportunity-read
eligibility. Enforce company and each relevant effective record scope at Request and Action, including
non-HTTP callers. The duty alone grants no pipeline read, general directory, Party, Finance,
active reassignment, closure/reopen or current-owner editing authority.

Do not automatically grant it to ordinary salespeople, editors, assigners, closers or general Sales
managers. Permit explicit assignment to designated authorised CRM/company administrators through
existing company-scoped access administration and its grant/anti-escalation rules. Initial authorised
bootstrap assignment is an explicit deployment operation with auditable principal/company evidence;
there is no inferred incumbent grant because the operation is new.

Administrator permission re-sync may include this duty only where an existing role is explicitly
defined as genuinely full-access across all registered capabilities and the target holds the eligible
company assignment. No inference from role names or current permission counts. Unproved eligibility
excludes automatic inclusion; valid explicitly assigned grants are preserved. Do not bypass scope by
converting a restricted role mandate into an unrestricted direct grant.

This is a narrow refinement of the generic administrator re-sync convention for this duty, not a
new wildcard-permission system or a global role redesign. Registration and enforcement must land
with safe grant/re-sync treatment in the same coordinated implementation. Audit every automatic
all-vocabulary consumer that could otherwise grant this duty, including bootstrap/demo helpers;
explicit named-permission assignment remains a distinct authorised operation.

Original evidence remains append-only; correction reason/actor/time and prior attribution survive.
Current Opportunity owner and closed row are unchanged. Notes remain closed administrative follow-up;
no named administrative assignee is introduced. Other accepted CRM, scope and confidentiality rules stay.

## Consequences

- **Positive:** correction specialists can hold a precise duty without active pursuit powers.
- **Negative:** generic all-vocabulary helpers need a scoped exception/eligibility check before rollout;
  companies must explicitly identify authorised principals or qualifying role mandates.
- **Requires:** separate correction/read/scope tests, anti-escalation, no blanket rollout grants,
  re-sync eligibility/idempotence/preservation tests and company isolation.
- **Requires:** missing full-access mandate evidence fails closed; deployment must not treat a
  snapshot or friendly role description/name alone as an automatically growing grant.

## Alternatives considered

**Reuse opportunity edit/assign/close** — rejected: distinct duty and unnecessary extra powers.
**Grant every Sales manager or named deploy admin** — rejected: title is not approved authority.
**Infer full access from all permissions today** — rejected: snapshot does not express future authority.
**Register without reviewing re-sync** — rejected: existing helper would grant it automatically.
**General wildcard/admin-role engine now** — excluded: explicit grants and narrow eligible re-sync
are sufficient; broader mandate-management UX is not authorised by this CRM slice.

## Research basis

Reuse 0112, [CRM policy evidence](../phases/phase-4-crm-policy-review.md), ADRs 0028/0062/0065/0089 and
[authorisation standards](../architecture/authorization.md). Source inspection on 2026-10-12 covers
`PermissionRegistry`, `SyncAdminPermissions`, `CreateAdminUser`, Role and Sales vocabulary. This is
an owner-approved project authority refinement, not a new vendor CRM lifecycle or market-design
question; no repeated ERP comparison is needed. Current code's unconditional re-sync is an
implementation gap to correct, not an unresolved policy conflict. Q42 is answered by this record.

## Related

- [0112](0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md)
- [Correction handoff](../phases/phase-4-crm-correction-specification.md) · [CRM domain](../domain/crm-corrections.md)
- [Security handoff](../phases/phase-4-crm-security-correction.md) · [Open questions](../OPEN-QUESTIONS.md)
- [0028](0028-dynamic-namespaced-permissions.md) · [0089](0089-organisation-units-and-record-scope.md)
