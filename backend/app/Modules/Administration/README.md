# Administration module

Company-scoped member lifecycle, role definitions and access assignments. See
[`docs/domain/access-administration.md`](../../../../docs/domain/access-administration.md) and
[ADR 0062](../../../../docs/decisions/0062-company-member-and-access-administration.md).

## Owns

- Member invitation, suspension/reactivation and invitation resend.
- Member profile maintenance and invitation acceptance through the shared authentication reset
  flow.
- Member directory filtering, scoped administration history and link-only password recovery.
- Company role CRUD and registered-permission discovery.
- Reviewable starter-role suggestions in the SPA; their names have no authorisation meaning.
- Role and exceptional direct-permission assignment with anti-escalation checks.
- The `administration.*` permission vocabulary.

It does not own authentication sessions/password mechanics, organisation units, multi-company
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
- Access changes carry a reason and before/after audit evidence.

## Entry points

- API: `members`, member lifecycle/access actions, `roles` and the registered `permissions`
  vocabulary under `/api/v1`.
- SPA: Settings → Members, Settings → Roles & Permissions, and the public invitation-acceptance
  and password-reset routes.
- Recovery/bootstrap remains server-operated through the administrator commands documented in
  [`deployment.md`](../../../../docs/architecture/deployment.md); the UI deliberately blocks
  self-suspension and self access editing.

## Related

- [`docs/domain/access-administration.md`](../../../../docs/domain/access-administration.md)
- [ADR 0062](../../../../docs/decisions/0062-company-member-and-access-administration.md)
- [ADR 0063](../../../../docs/decisions/0063-starter-roles-and-user-directory.md)
- [`docs/architecture/authorization.md`](../../../../docs/architecture/authorization.md)
