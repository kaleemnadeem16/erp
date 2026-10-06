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
  last_login_at            nullable; starts accumulating on successful login
  access_updated_at        nullable; initial or subsequent direct access assignment
  must_change_password     temporary credential cannot access business APIs
  credential_version      browser-session invalidation after credential replacement

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
- The UI calls this **Create user**: the account is created immediately with optional initial roles.
  Email activation is the default. When mail is unavailable, an administrator with both member-management
  and access-assignment capabilities can create without sending email and issue a generated temporary
  password for manual handoff; the recipient must replace it at first sign-in. No admin-selected
  permanent credential or password retrieval is supported.
- Accepting the invitation sets the password, verifies the email and transitions to `ACTIVE`.
- `INVITED` and `SUSPENDED` accounts cannot authenticate. Suspension also invalidates stored API
  tokens and browser sessions; global API middleware rejects a session if status changed after it
  was established.
- Reactivation returns a suspended account to `ACTIVE`; it does not restore removed assignments or
  manufacture a new password.
- Members are never hard-deleted in V1. Historic actor identity remains intact.
- Roles are company-scoped reusable bundles. Several roles and direct additions combine
  additively. There is no direct deny.
- Optional, editable starter-role templates help companies begin with Buyer, Receiving Clerk,
  Inventory Counter, Master Data Steward and Estimator. Saving is explicit and audited; templates
  omit high-risk posting, approval, reversal and administration powers. No code checks a role name.
- Member administration uses a server-paginated search/status directory and a detail/edit side
  panel. The directory shows name, email, roles, status, created, last sign-in and last access
  change; mobile defaults to cards with a table toggle. `last_login_at` has no historical backfill.
  The panel includes profile timestamps and recent, scoped administration events.
- A member manager can email an `ACTIVE` user a password-reset link; an `INVITED` member instead
  gets a resent activation invitation. Neither action exposes a token in the API response. A
  manager who also holds access assignment may instead issue a one-time displayed temporary
  password to an active or invited member, never to self, a suspended member or a member whose
  effective capabilities exceed the actor's. Issuance revokes
  existing device tokens and browser sessions, invalidates reset tokens and is audited without
  storing the cleartext. The user can reach only identity/password-change endpoints until they
  replace it. The admin must verify identity and use a trusted delivery channel.
- A role with assigned members cannot be deleted. Rename/permission changes apply to all assigned
  members and require a reason.
- Access assignment accepts an optional reason and records before/after snapshots. The actor may assign
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

Manual temporary password issuance requires **both** `administration.member.manage` and
`administration.access.assign`. Ordinary reset-link sending still requires only member management.

All queries are limited to the active company/team. Permission names are read from the application
registry; administrators cannot invent executable capabilities. Access screens never reveal
password hashes, reset tokens or sessions.

## Audit and non-HTTP boundary (2026-10-06)

Member profile/status/access/temporary-password and role save/delete Actions check the active
company for hydrated targets as well as the actor. Passing a model loaded under a different
company cannot bypass HTTP route scoping. Profile maintenance changes name/email only; password,
status and invitation metadata must use their dedicated workflows even when called without HTTP.
History requires member-read capability and projects only recognised administration events,
actor name, reason and time; raw properties, credentials and unrelated events are excluded.
`AccessAdministrationActionIsolationTest` proves these boundaries alongside the API/isolation suite.

`erp:audit-access-inventory` is a server-operated, read-only staging report of all companies,
members, roles, direct grants and token-holder metadata. Missing A1–A6 capabilities are review flags,
never automatic grants. Every member's human/service classification requires owner confirmation
because no service-account marker exists. See the [audit sign-off template](../architecture/access-security-audit.md#part-2-evidence-and-staging-sign-off-2026-10-06).
Delegated/unit administration still waits for Q26.

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
- `access_updated_at` tracks direct member assignment, not effective access changed indirectly by
  editing an assigned role. Consult role history for the latter; do not infer it from the member
  timestamp. `updated_at` includes unrelated profile/credential edits.

## Related

- [ADR 0062](../decisions/0062-company-member-and-access-administration.md)
- [ADR 0063](../decisions/0063-starter-roles-and-user-directory.md)
- [ADR 0065](../decisions/0065-role-first-member-access-and-manual-credential-recovery.md)
- [Authorisation](../architecture/authorization.md)
- [Multi-tenancy](../architecture/multi-tenancy.md)
- [Phase 0](../phases/phase-0-foundation.md)
