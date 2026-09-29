[← Decisions](README.md)

# 0063 — Offer reviewable starter roles and a user directory

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** Administration, authentication, role setup and member UI

## Context

[ADR 0062](0062-company-member-and-access-administration.md) established secure account creation through an invitation and company-configurable additive roles. Its first UI exposed a narrow member list and always-visible permission matrix. An administrator still needed to invent every role from scratch, could not inspect a member's change history, and had no working admin-initiated password-reset link in the SPA. The user's requested workflow is a searchable user directory with detail/edit panels, role-first assignment and ordinary account actions.

## Decision

- Offer a small set of **editable starter-role templates** in the role editor: Buyer, Receiving Clerk, Inventory Counter, Master Data Steward and Estimator. These are examples, not reserved names, automatic assignments or branches in application authorisation. They are saved only after an administrator reviews the permissions and supplies an audit reason. A company may rename, change or omit them.
- Templates contain only currently registered capabilities. They deliberately omit issue/post/reverse/approve, policy and security-administration powers. These duties need deliberate, separately reviewed role definitions. No template claims complete access to an unfinished module or replaces the per-feature permission retrofit.
- The Members screen is a server-paginated, searchable, status-filtered table. A row opens a side panel with profile, timestamps, roles, effective-access summary, recent administration history and lifecycle actions. An Edit action opens the same panel in edit mode.
- “Create user” creates the account immediately in `INVITED` state and can assign initial roles in the same transaction. The user privately sets the password through a one-use activation link; the administrator never chooses or sees it. Exceptional direct grants sit behind “Give custom permissions” rather than being the default form.
- For an active account, a member manager may send a password-reset link to the member's registered address. The reset uses the existing password broker and a dedicated SPA page. It is audited and does not expose a token or password to the administrator. Pending invitations use “Resend invitation” instead.
- The member history endpoint returns only whitelisted administration events for that member and company, with actor, timestamp and reason. It omits password tokens, hashes and unrelated activity properties.

## Consequences

- **Positive:** small companies can start with recognisable responsibilities without inventing a permission matrix; later companies can alter the roles without code changes.
- **Positive:** identity lifecycle, profile, access and audit evidence are discoverable in one place.
- **Negative:** starter roles are intentionally incomplete while ordinary module CRUD permission retrofits remain open. Administrators must review each role against their actual duties.
- **Requires:** working outbound mail and `FRONTEND_URL` for activation and reset links; browser UI is convenience, backend checks remain authoritative.

## Alternatives considered

**Auto-seed or auto-assign broad administrator roles** — rejected. It would grant unreviewed power and conflict with company configuration and least privilege.

**Administrator sets a new user's password** — rejected. A second person would know a reusable credential, while invitation and reset links already support private password setup.

**Always show all permissions on each user** — rejected. It obscures the normal role assignment path and invites hard-to-audit per-user matrices.

**Expose raw activity-log properties** — rejected. They may include unrelated internal values and make the history API's data contract fragile.

## Research basis

Question researched on 2026-09-29: how do mature ERPs provide common roles and user administration without hiding separation-of-duty or password ownership?

- [Dynamics 365 Finance and Operations security guidance](https://learn.microsoft.com/en-us/dynamics365/guidance/implementation-guide/security-strategy-product-oa) provides standard, responsibility-based roles, recommends copying/reviewing them before customising, and notes segregation of duties. Its directory/identity environment is more complex than this ERP's company-scoped model.
- [Odoo 19 Users](https://www.odoo.com/documentation/19.0/applications/general/users.html) creates a user with application access rights and sends an invitation, retains deactivated users, and offers an administrator-triggered reset-instructions action for an activated account.
- [Oracle Manage Application Users](https://docs.oracle.com/en/cloud/saas/applications-common/25c/faser/manage-application-users.html) centralises user maintenance and locking while keeping password reset in a security-specific surface. This supports keeping credential handling distinct from profile edits, though this ERP uses a single admin panel with a link-only action.

The shared pattern is a user directory, reusable job-oriented roles, explicit account status and a password-owner reset path. The vendors differ on prebuilt role breadth, identity providers and organisational scope. Here, narrow optional templates fit the existing capability vocabulary; broader job hierarchies and automatic assignments remain deferred.

## Related

- [ADR 0062](0062-company-member-and-access-administration.md)
- [Access administration](../domain/access-administration.md)
- [Authorisation](../architecture/authorization.md)
