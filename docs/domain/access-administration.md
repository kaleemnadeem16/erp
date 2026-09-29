[← Domain index](README.md)

# Access administration

Access administration turns the existing authentication and permission engine into an operable
company feature: invite people, suspend access, define reusable roles, assign roles/direct
exceptions and inspect the effective result without server or database access.

## Where it sits

```text
registered module capabilities → company roles → member assignments → effective permissions
                                      ↘ exceptional direct additions ↗
```

Capabilities are application-owned; roles and assignments are company-owned. Record/department
scope and approval authority remain separate from capability assignment.

## Schema

```text
users (existing, extended)
  id, company_id, name, email, password
  status                    INVITED | ACTIVE | SUSPENDED
  invited_at, invited_by    nullable; latest invitation event
  suspended_at, suspended_by nullable; cleared on reactivation
  email_verified_at

roles (existing Spatie table, extended)
  id, team_id, name, guard_name
  description               nullable company-facing purpose

model_has_roles / role_has_permissions / model_has_permissions
  existing Spatie team-scoped assignment tables

activity_log (existing)
  member/role lifecycle and access changes include reason and before/after snapshots
```

## Behaviour

- Invitation reserves the globally unique login email, creates an `INVITED` user and sends a single-use,
  expiring set-password link through Laravel's password broker.
- Accepting the invitation sets the password, verifies the email and transitions to `ACTIVE`.
- `INVITED` and `SUSPENDED` accounts cannot authenticate. Suspension also invalidates stored API
  tokens and browser sessions; global API middleware rejects a session if status changed after it
  was established.
- Reactivation returns a suspended account to `ACTIVE`; it does not restore removed assignments or
  manufacture a new password.
- Members are never hard-deleted in V1. Historic actor identity remains intact.
- Roles are company-scoped reusable bundles. Several roles and direct additions combine
  additively. There is no direct deny.
- A role with assigned members cannot be deleted. Rename/permission changes apply to all assigned
  members and require a reason.
- Access assignment requires a reason and records before/after snapshots. The actor may assign
  only permissions already present in their own effective set, including permissions contributed
  indirectly by a selected role.
- Self-suspension and self access-editing, including changing a role assigned to oneself, are rejected. Server recovery remains documented for the
  designated administrator.

## Authorisation and data scope

- `administration.member.view`: list/show company members and their effective access.
- `administration.member.manage`: invite, resend, suspend, reactivate and edit profile metadata.
- `administration.role.view`: list/show roles and registered permission vocabulary.
- `administration.role.manage`: create/update/delete unassigned company roles.
- `administration.access.assign`: assign roles/direct additions to another company member.

All queries are limited to the active company/team. Permission names are read from the application
registry; administrators cannot invent executable capabilities. Access screens never reveal
password hashes, reset tokens or sessions.

## Landmines

- Registration is not assignment. A newly shipped permission grants nobody access until a role or
  direct grant is updated.
- Direct grants only add. Removing a direct row cannot revoke the same permission inherited through
  a role.
- Deleting users corrupts actor history and breaks company-scoped actor foreign keys; suspend them.
- A hidden button is not enforcement. Requests and consequential Actions still authorise on the
  backend.
- `team_id` currently represents the company assignment context. Do not reinterpret it as a
  department or warehouse before Q26 is resolved.

## Related

- [ADR 0062](../decisions/0062-company-member-and-access-administration.md)
- [Authorisation](../architecture/authorization.md)
- [Multi-tenancy](../architecture/multi-tenancy.md)
- [Phase 0](../phases/phase-0-foundation.md)
