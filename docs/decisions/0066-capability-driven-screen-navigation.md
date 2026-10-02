[← Decisions](README.md)

# 0066 — Drive screen navigation from effective capabilities

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** SPA navigation, protected routes, landing page, permission rollout

## Context

Role assignment and backend checks existed, but most SPA routes and navigation links checked only authentication. A user with the two-capability Buyer starter role therefore saw Dashboard, Inventory, Stock Operations and Estimating beside their purchasing work. Hiding a sidebar link alone would leave the same screen reachable by typing its URL. Conversely, adding a `view` check to every existing API index/show endpoint would break cross-module item, category and other reference pickers: the current authorisation retrofit explicitly keeps those shared reads open until a field/data-scope design is complete.

## Decision

- One client-side `screen-access` map binds each current route family to the **existing effective capability names** needed to use that screen. It checks permission strings, never role names. The app shell's desktop sidebar, quick links, mobile primary tabs, More sheet and settings menu use the same map as the protected route gate. Empty groups disappear. A newly added protected route without a map entry fails closed in the SPA.
- A workflow with distinct create/post/issue capabilities is enterable by anyone holding one of that workflow's capabilities; the screen itself continues to gate each action by its specific capability. Item/category management, Material Preview, administration and policy settings use their existing explicit permissions.
- A subset of legacy setup screens has no dedicated capability and still has incomplete server-side authorisation. Until that retrofit, display these only to a user holding **both** `administration.member.manage` and `administration.access.assign`; this is a conservative presentation rule, not a replacement for backend enforcement. Read-only stock overview/ledger are available to users holding an Inventory operational capability. Company printing setup follows the temporary legacy-setup rule; self-service Account and Appearance remain available to every authenticated user.
- Dashboard is a Phase 0 placeholder, not yet a permission-aware operational dashboard. It is shown only under the temporary legacy-setup rule. For everyone else, `/` redirects to the first permitted work area; a user with no operational capability lands on Account. A denied direct URL shows a clear access message and does not mount its screen.
- This is **not** a data-security boundary. API requests, field projections, record scope and consequential Actions must retain or gain their own server-side checks. In particular, the open reference reads and sensitive-field work in the authorisation retrofit are not declared complete by this navigation change.

## Consequences

- A Buyer with `purchasing.purchase-order.create` and `purchasing.item-supplier.manage` lands in Purchase Orders and sees those work areas plus self-service settings, not unrelated menus. No company role name is special-cased.
- The map is a temporary bridge for legacy screens. As each resource gains an explicit view/manage capability and server-side enforcement, replace its inferred/legacy screen rule with that capability in the same change and keep its browser test current.
- A direct API client can still reach authenticated-only reads or incomplete legacy CRUD. The backend retrofit remains separately tracked; hiding the SPA screen must never be cited as proof those calls are secure.

## Alternatives considered

**Hide navigation but leave direct URLs open** — rejected; bookmarks and manual URLs would keep showing unauthorised screens.

**Use role names such as `Buyer` in the router** — rejected; roles are editable company data and the same capabilities can be granted through several roles or direct exceptions.

**Immediately require view permissions on every API read** — rejected for this slice because many reads are shared lookup dependencies, and the sensitive-field/record-scope model is not complete. That needs a separate, tested backend migration rather than an accidental broad 403 rollout.

**Show an empty dashboard to restricted users** — rejected; it provides no useful work and leaves a misleading primary tab. Redirecting to permitted work is clearer until a real role-aware dashboard is designed.

## Research basis

Existing [authorisation architecture](../architecture/authorization.md) and [authorisation retrofit audit](../architecture/authorization-retrofit.md) already cover capability-vs-role checks, server authority and the reference-read blast radius. [ADR 0062](0062-company-member-and-access-administration.md) and [ADR 0063](0063-starter-roles-and-user-directory.md) establish company-configured additive roles and the Buyer starter assignment. Those records answer this screen-gating question; no new vendor comparison is needed for this presentation correction under the [reuse-first rule](../architecture/research-and-design.md).

## Related

- [Authorisation](../architecture/authorization.md)
- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [User-interface delivery](../architecture/user-interface.md)
