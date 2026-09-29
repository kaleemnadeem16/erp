[← Decisions](README.md)

# 0065 — Keep member access role-first and permit controlled manual credential recovery

- **Status:** accepted
- **Date:** 2026-09-29
- **Supersedes:** [0062](0062-company-member-and-access-administration.md) and [0063](0063-starter-roles-and-user-directory.md) where they require a reason and forbid any administrator-visible password
- **Affects:** Administration, authentication, member directory and mobile UI

## Context

The first administration slice required a reason for every access edit and supported only emailed activation/reset links. It also displayed too much of the permission vocabulary at once and did not distinguish a profile update from an access update. These choices make day-to-day user setup harder; a mail outage can prevent both initial activation and password recovery. The user explicitly requested an administrator handoff path. This changes the prior accepted credential decision, so the exception needs a security boundary rather than an undocumented shortcut.

## Decision

- Role assignment is the default form. The custom-permission option starts unchecked, including for a member who already has direct grants. Existing grants remain visible as a count and are preserved unless the custom editor is opened and changed. Registered permissions are grouped in collapsed, accessible accordions with selected/total counts.
- A reason for an initial or subsequent member access assignment is optional. Actor, target, timestamp and before/after roles/direct grants are still recorded. Status changes and role-definition changes retain their existing reason requirement.
- The member directory shows name, email, status, roles, created time, last sign-in and last access change. `access_updated_at` records initial access assignment and subsequent member role/direct-grant saves, not profile edits or password actions. `last_login_at` starts accumulating from this release; no historical login time is invented. A mobile card view is available by default under `md`, with a table toggle and persisted preference per [ADR 0015](0015-capacitor-for-mobile.md).
- Email invitation and reset link remain the normal path. An administrator holding **both** `administration.member.manage` and `administration.access.assign` may create a member without sending mail and issue a system-generated 32-character temporary password, or issue one to an existing active/invited member. Self-reset, suspended targets and targets with any capability the administrator does not hold are forbidden; otherwise this would bypass the grant guard by impersonation. The admin confirms the disruptive action, sees the credential once over a no-store response, and must deliver it through a trusted separate channel after identity verification. The server stores only its hash; activity records never contain it.
- Issuance activates an invited user, invalidates password-broker tokens and device tokens, increments a session credential version, and requires the member to replace the temporary password before any business API use. The member can access only identity endpoints until then. Self-service password change clears the requirement and again revokes other credentials. A replacement through the ordinary reset link also clears it.
- Do not create a new permission string yet: the conjunction of existing lifecycle-management and access-assignment capabilities is narrower than either alone, and follows the current administration boundary. Revisit a dedicated credential-reset capability before delegated administrators are introduced.

## Consequences and limitations

- Manual handoff is an exception, not an automatic email replacement. An administrator knowing a temporary credential is a residual risk until the user changes it; out-of-band identity checking and delivery are organisational duties. There is no way to retrieve the credential again—issue another if lost.
- `last_login_at` records successful application authentication from this release. It is not a complete historical sign-in log or a substitute for an identity-provider audit.
- The access timestamp tracks direct member assignment changes. Editing the contents of a role changes effective permissions of its assignees, but does not update every member's `access_updated_at`; the role's own audit history is authoritative for that case. A future effective-access-history report should join both sources.
- Create-without-email uses the ordinary member-create transaction followed by credential issuance. If the second request fails, the user remains `INVITED`; the UI tells the administrator to open the account and retry issuance. No silently active account is created.

## Alternatives considered

**Keep email-only recovery** — rejected because outbound mail is an operational dependency that can fail exactly when access is needed.

**Let the administrator choose a reusable password** — rejected because predictable/reused secrets can be introduced and there is no reason to keep one known by a second person after handoff. A generated temporary password plus mandatory replacement provides the requested fallback with a smaller exposure window.

**Make the reason mandatory** — rejected for routine access changes at the user's request. The immutable before/after audit remains; an optional explanation still helps when one exists.

**Infer access change from `users.updated_at`** — rejected because profile and credential edits touch that column. A separate timestamp gives the requested meaning without mislabelling unrelated updates.

## Research basis

Question checked 2026-09-29: what user-directory and password-recovery controls do mature ERPs expose, and how should a manual handoff differ from normal recovery?

- [Odoo 19 Users](https://www.odoo.com/documentation/19.0/applications/general/users.html) documents a searchable user administration flow, application access rights, invitation/reset instructions, and an administrator action to change a local user's password.
- [Oracle Manage Application Users](https://docs.oracle.com/en/cloud/saas/applications-common/25c/faser/manage-application-users.html) documents administrator password reset as a policy-controlled Security Console operation, distinct from routine account maintenance.
- [Dynamics 365 Finance and Operations user log/dormant report](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/security-gov-user-dormant-report) includes name, account status and last login for administration review, explicitly leaving last login blank for users never seen signing in.
- [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html) treats authenticator recovery and replacement as security-sensitive; this informed short-lived exposure, revocation and mandatory replacement of a credential temporarily known to an administrator. Our one-time display is a project design choice, not a claim that NIST prescribes this exact UI.

The products converge on reusable access assignments, a user directory, explicit account state and administrator-assisted recovery, but differ in policy and identity-provider coupling. This ERP keeps company-scoped RBAC and its own local authentication; it does not copy a vendor's organisation or identity model.

## Related

- [Access administration](../domain/access-administration.md)
- [Authorisation](../architecture/authorization.md)
- [Research-before-design](../architecture/research-and-design.md)
