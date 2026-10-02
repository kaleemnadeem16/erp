[← Decisions](README.md)

# 0062 — Administer company members through invitations and additive roles

- **Status:** superseded by [0065](0065-role-first-member-access-and-manual-credential-recovery.md) for optional reasons and manual temporary credentials
- **Date:** 2026-09-28
- **Affects:** Phase 0, authentication, administration UI, every capability-gated module

## Context

The application already has company-scoped users, Spatie roles/direct permissions and stable
module-owned permission names. It has no member list, invitation workflow, role editor or access
assignment UI. The only production creation path is the server-side `erp:create-admin` command.
Continuing to add capability checks without an ordinary administration path would leave a secure
engine that companies cannot operate.

The current `users.company_id` model means one login belongs to one company. Q26 still owns future
organisation units and delegated administration; the multi-company roadmap separately owns a
user-to-company membership model. This slice must make today's company usable without pretending
those later scope decisions have been made.

## Decision

- Product language says **Members**, while the underlying authentication model remains `User`.
- A permitted administrator invites a member by name and email. The system creates an `INVITED`
  account with no administrator-known password and emails a time-limited, single-use set-password
  link. Successful password setup verifies and activates the account.
- Members are `INVITED`, `ACTIVE` or `SUSPENDED`. Suspension blocks new and existing authenticated
  requests and revokes tokens/sessions. Members are not hard-deleted because business/audit rows
  retain actor foreign keys.
- Company-configured roles are the normal assignment mechanism. A member may hold several roles;
  their permissions are additive. Direct permissions remain available as visible, exceptional
  additions and never act as denies.
- Role definitions bundle only application-registered permissions. They have company-scoped names
  and descriptions and cannot be deleted while assigned.
- Viewing members, viewing roles, managing member lifecycle, managing role definitions and
  assigning access are distinct capabilities:
  `administration.member.view`, `administration.role.view`,
  `administration.member.manage`, `administration.role.manage` and
  `administration.access.assign`.
- An access administrator cannot grant any permission they do not effectively hold. They cannot
  change their own access or suspend themselves through the UI/API. This prevents ordinary
  privilege escalation and accidental self-lockout; server-side recovery remains available for
  the designated deployment administrator.
- Every access change records actor, target, reason and before/after role/direct-permission
  snapshots in the existing company-scoped activity log.
- V1 is company-scoped. It does not add departments, warehouses as security scope, group/identity
  provider synchronisation, automatic role rules, temporary grants or multi-company membership.

## Consequences

- **Positive:** capability enforcement becomes operable without database access or Tinker.
- **Positive:** invitation avoids sharing or storing an administrator-selected initial password.
- **Positive:** roles remain the auditable default while the already-chosen direct-grant escape
  hatch stays available.
- **Positive:** stable permissions and company-configured roles allow later modules to appear in
  the same editor without changing this administration model.
- **Negative:** one person who works in several companies still needs separate company accounts
  until the multi-company membership design lands.
- **Negative:** administrators cannot edit their own access; a second access administrator is
  needed for ordinary changes to that account.
- **Requires:** outbound mail for real invitations, a configured frontend URL, and explicit access
  grants to at least one administrator before relying on the screens.

## Alternatives considered

**Administrators choose initial passwords** — rejected. It exposes a reusable secret to another
person and creates an avoidable password-delivery problem.

**Direct permission matrix as the main UI** — rejected. It scales poorly, obscures job intent and
cannot subtract a permission inherited from an additive role.

**Hardcoded Owner/Buyer/Storekeeper roles** — rejected. ADRs 0028 and 0057 deliberately make role
bundles company-configurable while application code checks stable capabilities.

**Delete departed users** — rejected. Actor references and audit history must remain truthful;
suspension is the reversible lifecycle operation.

**Build departments and delegated administration now** — rejected. Q26 has not selected an
organisation model, and one Spatie team dimension cannot safely stand for company, department and
warehouse simultaneously.

## Research basis

Question researched on 2026-09-28: how do mature ERPs onboard internal users, bundle access,
disable access and prevent an administrator from granting authority they do not possess?

- [Odoo Users](https://www.odoo.com/documentation/16.0/applications/general/users.html) creates a
  user from Settings, sends an invitation automatically, and deactivates rather than requiring
  deletion.
- [Odoo Access Rights](https://www.odoo.com/documentation/16.0/applications/general/users/access_rights.html)
  applies rights through groups and warns explicitly about locking out the administrator.
- [Dynamics 365 role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security)
  assigns users to responsibility-oriented roles and treats multiple roles cumulatively.
- [Microsoft role assignment guidance](https://learn.microsoft.com/en-us/power-platform/admin/assign-security-roles)
  prevents an assigner from granting a role with privileges above their own and supports several
  roles per user.
- [Oracle Security Console](https://docs.oracle.com/en/cloud/saas/applications-common/25c/faser/overview-of-security-console.html)
  centralises user creation, locking, role assignment and role analysis behind a dedicated
  security administrator capability.
- [SAP Maintain Business Users](https://help.sap.com/docs/btp/sap-business-technology-platform/maintain-business-users)
  separates business-user maintenance from business-role definition and supports role assignment,
  locking and unlocking.

The convergence is strong: lifecycle administration is explicit; disabled users are retained;
roles are reusable bundles; multiple assignments are additive; and security administration is a
protected capability of its own. The products diverge on identity-provider ownership, automatic
assignment rules and organisation hierarchies. Those differences map to later integration/Q26
work rather than this company's first operable administration surface.

## Related

- [ADR 0028 — Dynamic namespaced permissions](0028-dynamic-namespaced-permissions.md)
- [ADR 0057 — Stable capabilities with dynamic assignment](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
- [Access administration domain](../domain/access-administration.md)
- [Authorisation architecture](../architecture/authorization.md)
- [Research-before-design standard](../architecture/research-and-design.md)
