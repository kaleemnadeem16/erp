# Administration module

Company-scoped member lifecycle, role definitions and access assignments. See
[`docs/domain/access-administration.md`](../../../../docs/domain/access-administration.md) and
[ADR 0062](../../../../docs/decisions/0062-company-member-and-access-administration.md).

## Owns

- Member invitation, suspension/reactivation and invitation resend.
- Member profile maintenance and invitation acceptance through the shared authentication reset
  flow.
- Member directory filtering, scoped administration history, email recovery and controlled
  manual temporary-credential issuance.
- Company role CRUD and registered-permission discovery.
- Reviewable starter-role suggestions in the SPA; their names have no authorisation meaning.
- Role and exceptional direct-permission assignment with anti-escalation checks.
- The `administration.*` permission vocabulary.

It does not own authentication session implementation or self-service password policy, organisation units, multi-company
membership, approval workflows or business-module capability names.

## Permissions

- `administration.member.view` / `administration.member.manage`
- `administration.role.view` / `administration.role.manage`
- `administration.access.assign`

The backend is authoritative. The SPA gates navigation/actions for usability only.

## Invariants

- Members are retained and suspended, never deleted.
- Roles/direct permissions are additive.
- An actor cannot grant a capability they do not hold or edit their own access.
- Role and member queries remain inside the active company/team.
- Roles use the `web` guard even when the request is authenticated through Sanctum. Keep the
  model's default guard explicit: role-list member counts build the `users` relation on an
  unhydrated model, and Sanctum has no user provider for that relation.
- Member access changes carry before/after audit evidence; their reason is optional. Role-definition
  and member-status changes still require a reason.
- A manual temporary password requires both member-management and access-assignment permissions,
  cannot target self or a suspended member, is shown once and forces a private password change.

## Entry points

- API: `members`, member lifecycle/access actions, `roles` and the registered `permissions`
  vocabulary under `/api/v1`.
- SPA: Settings → Members, Settings → Roles & Permissions, and the public invitation-acceptance
  and password-reset routes.
- Recovery/bootstrap remains server-operated through the administrator commands documented in
  [`deployment.md`](../../../../docs/architecture/deployment.md); the UI deliberately blocks
  self-suspension and self access editing.

## Staging audit

`erp:audit-access-inventory` reads all company/member/role/direct-grant and token-actor metadata
without changing assignments or emitting credentials. Its flags need owner review; service actors
must be identified by the owner. The sign-off template lives in the access-security audit tracker.
All member/role mutation Actions also refuse hydrated foreign targets; profile maintenance accepts
name/email only. Scoped history response assertions accompany the existing API isolation tests.

## Custom-field configuration

Owns the settings API/UI over the existing company-scoped CustomFieldDefinition foundation.
CreateCustomField checks administration.custom-field.manage and validates the same safe entity,
type and uniqueness contract for non-HTTP callers. Definition reads require that capability or
maintenance authority for the requested entity. The rollout grants full administration bundles
only, per company; existing master-data maintainers gain no configuration authority.
Supported forms, reserved reference types, demo data and the authorisation checklist are recorded
in [extensibility](../../../../docs/architecture/extensibility.md#implemented-custom-field-settings-2026-10-06).

## Related

- [`docs/domain/access-administration.md`](../../../../docs/domain/access-administration.md)
- [ADR 0062](../../../../docs/decisions/0062-company-member-and-access-administration.md)
- [ADR 0063](../../../../docs/decisions/0063-starter-roles-and-user-directory.md)
- [ADR 0065](../../../../docs/decisions/0065-role-first-member-access-and-manual-credential-recovery.md)
- [`docs/architecture/authorization.md`](../../../../docs/architecture/authorization.md)
