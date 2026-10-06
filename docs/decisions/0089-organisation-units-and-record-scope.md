[← Decisions](README.md)

# 0089 — Organisation units and record scope, first applied to inventory by warehouse

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Administration (organisation units, memberships, scope limits), a shared scope resolver, Inventory stock reads and physical counts, Access Administration; part-resolves Q26

## Context

Capabilities say what a person may *do*; nothing yet says *to which records*. Every read and write is company-wide: a person who may view stock sees every warehouse, and the access audit (A6, ADR 0083) records that the stock-read capabilities are company-wide "until Q26 is decided". [ADR 0028](0028-dynamic-namespaced-permissions.md) and the [authorisation model](../architecture/authorization.md#record-and-field-visibility) already require that a permission must not silently mean "all records", that scope be applied to list, count, search, lookup, show and write, and that cross-department work not need one role per department pair. The existing research ([ADR 0062](0062-company-member-and-access-administration.md#research-basis); the Odoo record-rule and Dynamics extensible-data-security comparison in Q26) shows mature ERPs separate record scope from operation rights and enforce it on the server.

Facts that shape the design (checked in the code, 2026-10-06):

- Company scoping is a global model scope. **A record scope cannot be one.** Internal workflows read stock layers on behalf of whoever is acting (FIFO and AVCO costing); filtering those reads by the actor's units would silently corrupt a restricted clerk's own receipts and issues. Scope must be applied at the API read boundary and at the entry of writes.
- Stock carries a warehouse (through its location). **Purchase orders and goods receipts store no creator, owner or ship-to**, so they cannot be scoped by owner or unit until that data exists. Inventory is the one area scopable with today's data.
- The inventory overview and AVCO pools aggregate across warehouses (and the overview adds company-wide purchase-order quantities), so they cannot be limited to a warehouse without a different computation.

The owner decided (2026-10-06): organisation units as a company-defined tree; inventory by warehouse first; delegated administration later.

## Decision

- **Organisation units.** A company defines its own tree (`organisation_units`: code, name, parent, status), for example Company › Karachi site › Warehouse team, plus departments. A person can be a **member of several** units (`organisation_unit_members`). A unit can be bound to resources (`organisation_unit_resources`, today `warehouse`): a warehouse belongs to at most one unit, and a warehouse bound to no unit is visible only to unrestricted users (fail closed). Membership in a unit includes its descendants. Spatie Teams stays the company boundary; units are separate and do not change how roles are assigned.
- **Scope is a limit on an existing grant, not a new kind of permission.** An assignment — a role given to a person, or a direct permission — is company-wide by default, exactly as every existing assignment already is, so **no backfill and no behaviour change** for anyone until an administrator limits something. An administrator may mark an assignment **"only my units"** (`access_scope_limits`, scope `MY_UNITS`; the column is a scope name so own/assigned scopes can follow). Capabilities, roles and the role editor are unchanged.
- **Effective scope** for a person and a capability is resolved by one service (`RecordScope`) over additive grants: if any grant that confers the capability is unrestricted → **all**; if none confers it → **none**; otherwise the union of the units the person belongs to (with descendants). A restricted person with no membership sees nothing, not everything. Direct grants cannot deny, as before.
- **Enforcement is explicit and at the boundary.** The API applies the scope to list, count (cursor/pagination totals), search, filter, sort, include and show (out of scope is a **404**, so existence is not confirmed), and writes check it before acting. It is never a global model scope, so internal workflows are unaffected.
- **First slice — Inventory by warehouse.** Applied to stock layers, the stock ledger (a movement is visible when either side is in scope) and physical counts (list/show and create, add line, count, post, cancel). The **overview and AVCO pools require unrestricted scope**: a restricted person receives 403 there rather than a figure that quietly includes other warehouses. Every other Inventory, Purchasing and Estimating surface is listed with its status in the audit tracker's coverage map; a test fails when a stock-bearing route has no declared status.
- **Administration.** New capabilities `administration.organisation.view` and `administration.organisation.manage` (units, memberships, bindings). Scope limits are set with the existing member-access action under `administration.access.assign`, audited like any access change. Units, members and bindings are company-scoped with composite foreign keys, tenant-isolation tested.
- **Deferred, by decision:** delegated administration (a unit head assigning access to their people); own-record and assigned-record scopes; time-bounded task access; scope for purchasing documents (needs a creator and ship-to recorded first, then a backfill); a warehouse-limited inventory overview and AVCO pool view; applying scope to Estimating's availability and to approvals.

## Consequences

- **Positive:** a multi-site company can limit people to their warehouses with the roles they already have; nothing changes for a company that never defines units; one resolver and one boundary pattern for every later module.
- **Positive:** units are generic (a tree bound to resources), so departments, sites and cost centres are additions, not redesign.
- **Negative:** until each surface is scoped, "limited to my units" protects only the listed surfaces. The coverage map and its test make that explicit instead of implying more.
- **Negative:** a restricted person has no overview or pool figures for now (403).
- **Requires:** staging verification with a real multi-warehouse company before anyone is limited; an owner-approved plan for who is limited, since limiting is deliberate and nothing limits anyone automatically.

## Alternatives considered

**Use Spatie Teams as organisation units** — rejected: one team dimension cannot be company and department and warehouse, and migrating every assignment is risky for no gain.

**A global model scope** — rejected: it corrupts internal costing workflows (see Context).

**A scope level on every permission or role definition** — rejected as heavier than needed: it changes the role editor and every role, where limiting an *assignment* reuses the roles companies already have and keeps every existing assignment unchanged.

**One role per department pair** and **direct grants as denials** — rejected (authorisation model).

**Restricted overview computed from in-scope locations only** — rejected for now: it would mix company-wide purchase-order quantities and pool-level values into a figure that looks warehouse-specific. A correct warehouse-scoped overview is its own slice.

## Research basis

No new market comparison. The existing research is sufficient: the Odoo record-rule and Dynamics extensible-data-security comparison recorded under Q26 (record scope separate from operation rights, server-side enforcement, default-allow versus constrained-table policies), [ADR 0062's research basis](0062-company-member-and-access-administration.md#research-basis), and [ADR 0028](0028-dynamic-namespaced-permissions.md). Default-allow (no limit means company-wide) is chosen deliberately for rollout safety and matches Odoo's behaviour when no rule applies; the stricter Dynamics-style constrained policy would lock out every existing user on release.

## Related

- [Authorisation: record and field visibility](../architecture/authorization.md#record-and-field-visibility), [access audit](../architecture/access-security-audit.md)
- [ADR 0083](0083-layered-stock-read-capabilities-and-blind-counting.md), [Open questions Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)
