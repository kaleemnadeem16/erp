[← Decisions](README.md)

# 0068 — Authorise Purchasing document reads separately from writes

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** Purchase Orders, Goods Receipts, QA dispositions, Item Suppliers, company roles and screen navigation

## Context

Purchasing writes already require distinct capabilities, but every authenticated company user could list and fetch purchase orders, receipts, QA decisions and item-supplier terms directly. ADR 0066 hid screens for users without matching capabilities, explicitly without claiming to secure these API reads. The earlier [authorisation retrofit](../architecture/authorization-retrofit.md) left reads open because Receiving, QA and Finance need records even when they cannot create or post them. Keeping that need does not require granting every company member all Purchasing documents.

## Decision

- Register `purchasing.purchase-order.view`, `purchasing.goods-receipt.view` and `purchasing.item-supplier.view`. These grant read-only list/detail access to the named resource. Receipt view also covers its inline QA disposition list/detail. They do not grant any mutation.
- The existing action permissions continue to carry the minimum read access needed to perform that action. PO create/issue/cancel/short-close and cutover commit can read POs. Receipt create/post/reverse and QA posting can read receipts **and POs**, because receiving and QA operate against issued PO lines. PO create and PO view can read item-supplier rows needed for sourcing. An item-supplier manager can read its own rows. These dependencies are coded in one Purchasing-owned read-access matrix; role names are never checked.
- Enforce the matrix at every Purchasing document list/detail endpoint, including QA disposition detail. Invalid include/filter input still receives normal validation **after** authorisation; a user without read access receives 403 regardless of an ID or query string. Existing company scopes remain in force.
- The SPA shows a resource screen for its explicit `view` permission or its existing resource action permissions. Cross-workflow API reads do not automatically add another screen to navigation.
- Commercial cost/price fields are **not** made public or hidden by this decision. A view permission currently returns the existing resource projection, including commercial amounts. Separate field-level cost visibility and the minimum projection required by receiving are still unresolved in the authorisation retrofit. Administrators must treat these view permissions as commercially sensitive until that work lands.
- Registration grants nobody a new capability. Before deploying enforcement, review each company role/direct grant: give `purchase-order.view` to Finance or other PO-only readers, `goods-receipt.view` to receipt/QA-only readers, and `item-supplier.view` to sourcing-only readers as needed. Existing actors with the listed action permissions retain their operational reads; the configured administrator is synced by the normal deploy step. Verify effective access in the Members screen and test representative accounts before rollout.

## Consequences

- A member with no Purchasing capability cannot fetch these document collections or guess a detail ID, even though the SPA already hides the screens.
- Buyer and Receiving Clerk starter roles remain usable through their existing action capabilities. They are editable examples, not enforcement branches. Read-only Finance/QA roles can be built without granting write power, but existing company-created roles must be reviewed before deployment.
- There is still no department, warehouse or own-record scope (Q26). These permissions cover the whole company-scoped collection. Field-level commercial-cost protection is a separate next slice, not implied by document visibility.

## Alternatives considered

**Leave reads authenticated-only** — rejected: screen gating alone leaves a direct API data leak for unrelated members.

**Use a broad `purchasing.view`** — rejected: it would couple supplier terms, POs and receipts, and could not give Finance PO-only access.

**Require only the new view permission, even for a creator/poster** — rejected: it would break existing Buyer and Receiving Clerk roles and require every workflow role to receive a second grant before it could verify its own work.

**Hide commercial amounts by reusing `estimating.internal_cost.view`** — rejected: ADR 0057 explicitly scopes that permission to internal material/valuation cost, not supplier offers. A Purchasing-specific field projection requires a separate decision and UI contract.

## Research basis

Existing [authorisation architecture](../architecture/authorization.md), [ADR 0028](0028-dynamic-namespaced-permissions.md) and [ADR 0063](0063-starter-roles-and-user-directory.md) establish company-configured roles around stable capabilities. For this security boundary, current primary guidance was checked on 2026-09-29: [Dynamics 365 Finance role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security) separates duties and privileges and permits read access apart from mutation and data policy; [Oracle Procurement security reference](https://docs.oracle.com/en/cloud/saas/procurement/25c/oapcm/security-reference-for-procurement.pdf) lists distinct procurement viewing privileges; [Laravel authorisation](https://laravel.com/docs/12.x/authorization) supplies the request/policy enforcement pattern. The common pattern is task-specific read privilege with role composition. Our additive capability model makes the minimal cross-workflow read dependencies explicit rather than assuming a role name or universal read access. Neither vendor's larger organisational-scope model is copied before Q26.

## Related

- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [Purchasing](../domain/purchasing.md)
- [ADR 0066](0066-capability-driven-screen-navigation.md)
- [Access administration](../domain/access-administration.md)
