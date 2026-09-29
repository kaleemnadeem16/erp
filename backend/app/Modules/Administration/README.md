# Administration module

Company-scoped member lifecycle, role definitions and access assignments. See
[`docs/domain/access-administration.md`](../../../../docs/domain/access-administration.md) and
[ADR 0062](../../../../docs/decisions/0062-company-member-and-access-administration.md).

## Owns

- Member invitation, suspension/reactivation and invitation resend.
- Company role CRUD and registered-permission discovery.
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
- Access changes carry a reason and before/after audit evidence.
