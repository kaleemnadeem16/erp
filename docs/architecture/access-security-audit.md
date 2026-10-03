[← Architecture](README.md)

# Access and sensitive-data audit

**Baseline:** 2026-10-02, read-only triage against the `develop` checkout. **Status:** open;
this is not a certification that every endpoint or field is safe. No permission assignment,
account, price, or financial-visibility setting was changed by this audit.

This document covers the parts of the application beyond the original
[authorisation retrofit](authorization-retrofit.md). Use that tracker for its completed
Items/Purchasing/Estimating slices and its unresolved Item/Category GL-field decision. This
audit records newly confirmed gaps and the remaining inspection work in one place. Before
designing a new access policy, reuse existing ADR/domain research; if the behaviour is genuinely
new, follow the bounded primary-source comparison in
[research-and-design.md](research-and-design.md).

## Evidence and confidence

The first pass inspected API route files, controllers, Form Requests, selected Actions and
Resources across all nine backend modules and the core routes. A focused architecture test,
`php artisan test tests/Architecture/PermissionCheckRegistrationTest.php --compact`, passed
(3 tests, 5 assertions). That test proves literal capability names already used in checks are
registered; it **cannot** discover a missing check. The findings below are code-path findings,
not exploit tests. We have not inventoried live company assignments or exercised each route with
allowed and denied users. Later reviewers must record exact endpoint/Action coverage and tests.

## Confirmed gaps

| ID | Surface and evidence | Current consequence | Required decision / work |
|---|---|---|---|
| A1 | Core [exchange-rate routes](../../backend/routes/api/exchange-rates.php), [controller](../../backend/app/Http/Controllers/Api/V1/ExchangeRateController.php) and store/update requests | At the audit baseline, any authenticated user could create/update/delete rates. The `currency.exchange-rate.manage` HTTP/Action retrofit is now tested for allowed and denied access. | Assign a dev maintainer through the app. Separate approval/history and future import authority remain later decisions. |
| A2 | Accounting [routes](../../backend/app/Modules/Accounting/routes.php), [chart controller](../../backend/app/Modules/Accounting/Http/Controllers/Api/V1/ChartOfAccountController.php) and write Actions | At the audit baseline, authenticated users could create, alter/reparent and delete chart accounts. `accounting.chart-of-account.manage` now protects these writes at HTTP and Action boundaries; focused tests pass. | Grant dev maintainers through the app; Finance still decides any narrower read/field policy and future lifecycle separation. |
| A3 | Parties [routes](../../backend/app/Modules/Parties/routes.php), Party/Customer/Supplier controllers and write requests | At the audit baseline, authenticated users could maintain Party, CustomerAccount, SupplierAccount and supplier sites. Four resource capabilities now guard their requests and twelve Actions; the PO inline supplier-site add control follows its capability. Backend and browser tests pass. | Finance still decides field-specific policy for credit, terms and control-account IDs. |
| A5 | Approvals [ApprovalRequestResource](../../backend/app/Modules/Approvals/Http/Resources/ApprovalRequestResource.php) (`show`, audit `index`, `inbox`, `decide`) | At the review baseline, `amount_base` and the nested currency evidence (original amount, rate, date, source) went to every viewer of a request — submitter, anyone with `approvals.history.view`, past deciders, the actionable approver — regardless of Purchasing/Estimating cost visibility. **Fixed 2026-10-03**: the figures follow the subject's own field policy plus a need-to-know exception (see below). | Finance/Purchasing to confirm the need-to-know exception (approver sees the amount of the request they must decide, without line prices). |
| A6 | Inventory [stock-moves, stock-layers, avco-pools, inventory-overview](../../backend/app/Modules/Inventory/routes.php) and their request classes | At the review baseline, stock quantities, locations and movement history were readable by every signed-in user (only valuation was projected), so `estimating.inventory_availability.view` could not actually withhold stock levels, and a physical counter always saw the book quantity. **Fixed 2026-10-03 ([ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md))**: `inventory.availability.view` (overview/summary), `inventory.stock.view` (layers, pools, position; also gives availability) and `inventory.movement.view` (ledger) are enforced at the request boundary; counters count blind. A migration first grants them to everyone who already used the screens (not to a count-only role). | Verify in staging with representative warehouse, sales, counting and finance users before the release; follow-up (separate): `negative-stock-override-requests` reads. |
| A7 | Core [forgot-password](../../backend/app/Http/Controllers/Api/V1/AuthController.php) | At the review baseline an unknown address returned a 422 "cannot find a user" while a known one returned 200, so the endpoint revealed which addresses have accounts. **Fixed 2026-10-03**: every address (and a broker-throttled repeat) gets the same 200 answer; tests assert identical bodies. | None; bearer-token expiry is a separate open policy item in the coverage map. |
| A4 | Inventory [ItemResource](../../backend/app/Modules/Inventory/Http/Resources/ItemResource.php) and [CategoryResource](../../backend/app/Modules/Inventory/Http/Resources/CategoryResource.php) | GL account IDs are returned unconditionally on shared authenticated reads. This is an established open decision, not a newly introduced regression. | Finance decides whether those IDs are sensitive and who may edit/view them; then project fields and guard writes consistently. |

A1–A3 were confirmed missing **write** capability boundaries at the audit baseline; A1 and A2
have since been retrofitted. That is not evidence that every user has the same UI controls. A4
is a confirmed unconditional response field, not a claim that an account
ID alone discloses balances. Request validation, company scoping and workflow rules may still
apply; a missing capability check is a separate issue.

## Coverage map and next inspection

| Area | First-pass state | Still to verify |
|---|---|---|
| Core Auth/Account/Currency/UoM/ExchangeRate | A1 write boundary tested; login throttled (5/min), session `credential_version` and token revocation on password reset/change/suspension/temporary password reviewed; **forgot-password no longer reveals whether an account exists (A7, fixed 2026-10-03)**; `PATCH /account` edits the caller's name only | Bearer tokens never expire (`sanctum.expiration` null) — policy decision before the mobile client ships; shared reference lookups stay open by design. |
| Accounting | A2 write boundary now tested | Account-selection reads, history, future lifecycle rules and any narrower field policy. |
| Parties | A3 write boundary and PO inline site control verified | Field projections, credit/terms policy and future master-data screens. |
| Administration | Capability and anti-escalation checks; self-targeting refused for access, suspension and role edits (no path to zero administrators found); **cross-company member and role routes proven unreachable (`AccessAdministrationTenantIsolationTest`, 2026-10-03)** | Audit-history negative paths and delegated administration (Q26). |
| Approvals | Assignment/capability checks observed; **evidence/amount projection fixed and tested (A5)** | Remaining: who may read per-person authority ceilings (`approvals.history.view` can list them); free-text decision reasons are unfiltered; ADR-0070 consumers added later must implement `canViewEvidence`. |
| Estimating | Original CRUD retrofit tracked separately; material preview, stock-mix, variance and cost-breakdown reviewed 2026-10-03: cost and availability are projected by capability and proven on response bodies (`MaterialPreviewApiTest`: `assertStringNotContainsString` on cost figures), cost breakdowns return 403 without `estimating.internal_cost.view`; approval evidence now follows `estimating.internal_cost.view` (A5) | Action-level guards (ratchet list); Phase 4 adoption/quotation surfaces must be reviewed as they are built. |
| Imports | **No entry point exists**: the staging/validation/profile Actions are called only by tests and the demo seeder, and check no capability. `ImportStagingHasNoEntryPointTest` fails the moment an HTTP, console or job caller appears. | Any future import screen/command needs its own capability, per-record authority and a field policy on staged rows that carry prices/costs. |
| Inventory | Existing operational and valuation slices tracked separately; valuation fields (`on_hand_value`, stock-move valuation, pool value) are projected by `inventory.valuation.view`; **there are no export, download or print endpoints** (printing renders data the screen already holds); A4 open; **A6 fixed (ADR 0083)** | Negative-stock override request reads (workflow-duty gating); GL-field policy (A4); re-check when package-balance operations (ADR 0082) add routes. |
| Numbering | Endpoint-by-endpoint allowed/denied tests exist; **by-id cross-company access to series, versions, rules, counters and counter raises proven unreachable (2026-10-03)**; the allocation log has no read API | History/allocation-log screen, when built, needs `numbering.view` plus a field review (it stores supplier/customer codes). |
| Purchasing | Existing document/commercial-cost slices tracked separately; packaged purchasing reviewed 2026-10-03: supplier-offer prices/currency/price basis are projected by `PurchasingCommercialAccess` and the offer Action requires manage + commercial access; package writes need `inventory.item.manage` at request and Action; the PO line preview needs create | Future exports and any cross-module price evidence (approvals now covered, A5). |

An observed check is **not** an audit sign-off. [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)
still owns organisation/record scope; [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
still owns company PO policy. Neither should be silently decided by adding a role.

## Risk-ordered work queue

Development environment note (2026-10-02): `permission:register` was run for company 1 after
the FX and chart slices (90 vocabulary entries registered). This makes the two new capabilities
available to Access Administration. No member or role assignment was made by this task.
After the Parties slice, registration was rerun (94 entries) and the documented demo
administrator `user@erp.x1techs.com` was synced to all 94. The four new Parties permissions were
added to that administrator only; no Buyer or operational role was broadened.

- [ ] **Policy decision:** product and Finance identify actual FX, chart and Party maintainers,
  separate duties that must not be bundled, and decide which financial/master-data fields need
  narrower read or write access. Record the decision in an ADR/domain doc, using existing research
  first. Do not infer it from demo roles.
- [x] **A1 manual FX API:** `currency.exchange-rate.manage` is registered and checked at HTTP
  and non-HTTP write boundaries, while authenticated lookup remains open. The focused API and
  permission-registration suite passes (21 tests, 73 assertions, 2026-10-02).
- [ ] **A1 operational follow-up:** grant a dev maintainer through Access Administration and
  verify effective access in the app. Future rate import and calculation effects need their own
  tests/review; the current frontend has no rate-maintenance screen.
- [x] **A2 chart API:** `accounting.chart-of-account.manage` now protects create,
  edit/reparent and delete in requests/controller and Actions; shared account lookup remains.
  The chart API/model/permission-registration suite passes (25 tests, 65 assertions,
  2026-10-02).
- [ ] **A2 operational/field follow-up:** grant a dev chart maintainer in Access
  Administration; Finance must decide whether account lookup or cross-module account fields
  require narrower visibility. There is no chart-maintenance SPA screen yet.
- [x] **A3 Parties write slice:** four distinct capabilities protect requests and all twelve
  write Actions; the PO inline site-creation control follows the site capability. The complete
  Parties module and architecture suite passes (89 tests, 218 assertions), as does the focused
  browser spec (setup plus administrator and Buyer cases), 2026-10-02.
- [ ] **A3 financial-field follow-up:** decide customer credit/terms and customer/supplier
  control-account write/read visibility separately, including list/detail/lookup projections.
- [ ] **A4 GL fields:** implement the Finance decision for Item/Category edits and every list,
  detail, lookup, print and export projection; add negative field-presence tests.
- [x] **A5 approval evidence/cost visibility (2026-10-03):** `ApprovalSubject::canViewEvidence` + `ApprovalEvidenceAccess`
  project `amount_base`, `evidence` and the new `evidence_visible` flag on every approval response; response-body tests cover an
  auditor, the submitter, a cost viewer, the actionable approver and a past decider, and each registered subject's rule
  (`ApprovalEvidenceVisibilityTest`; Approvals + PO approval + Estimating approval suites: 61 tests, 364 assertions). The approval
  panel says when the amount is hidden. Open: whether the approver's need-to-know exception stays (approver decides on the total
  without seeing line prices on the PO), and the per-person authority-ceiling read policy.
- [x] **Write-route guard inventory (2026-10-03):** `tests/Architecture/WriteRouteGuardTest.php` walks every API POST/PUT/PATCH/DELETE
  route and fails unless it has a capability guard (Form Request `authorize()`, an inline controller gate, or an Action gate) or is
  named with a reason (sign-in/recovery, the caller's own account/session, approval decide/withdraw whose eligibility the Action
  checks). Three compute-only POSTs (numbering tester, PO line preview, material preview) are classed separately. The detector was
  proven by removing one request's guard (`POST api/v1/items` then failed). It proves a guard is *present*, not correct.
- [ ] **Action-level guards (open, ratchet):** about 45 write routes are guarded at the HTTP boundary only — their Action has no gate
  of its own, so a console command, job or future integration calling the Action is not stopped (the list is
  `ACTION_WITHOUT_GATE` in that test; new routes may not join it). Governance Actions go first (approval policy/authority/settings,
  the Estimating policies and reasons, Inventory/Purchasing/Company settings), then master-data CRUD (items, categories,
  warehouses, locations, item-suppliers, purchase orders). Each needs an actor parameter and its seeders/tests updated, so it is a
  sequence of small slices, not one change.
- [x] **Administration, Numbering, Imports and Core Auth negative paths (2026-10-03):** foreign-company member/role and series/counter
  routes are unreachable by id (new isolation tests); forgot-password no longer enumerates accounts (A7); the Imports Actions have no
  entry point and a test keeps it that way. Open from this pass: bearer-token expiry, and
  Action-level guards.
- [x] **A6 stock-read capabilities (2026-10-03):** ADR 0083. Three separate read capabilities enforced on the overview, layers, pools and
  ledger (request boundary, before includes/filters), blind counting by role, SPA screen access aligned, and a grant migration that
  preserves existing screen holders. `StockVisibilityTest` (each surface × each capability alone and none, includes/filters, body
  checks), `StockVisibilityRolloutTest` (grants, idempotence, the count-only exception, per-company) and the blind-count test pass
  with the Inventory, Estimating, Purchasing, Support and Architecture suites. Deployment verification in staging is still to be done
  and recorded.
- [ ] **Information-flow review (continuing):** complete the module inspections above and update this matrix with endpoint/Action/test
  references. New Inventory/Purchasing/Estimating features need their own check before sign-off.
- [ ] **Deployment verification:** enumerate each active company member, role, direct grant and
  service actor needing a new capability; verify effective access in staging before enforcement.
  Record who approved the map and the result. Re-run administrator permission sync. Do not grant
  a broad role merely to make a failing test or workflow pass.

### Safe release and exit criteria

Registration alone may ship as a labelled reserved vocabulary step. Before any fail-closed
enforcement release, apply and verify the approved assignments in each affected company, then
test representative allowed, denied and read-only users against the UI **and** API. Enforce one
slice at a time, with request/controller and Action guards, tenant/scope checks, sensitive-field
projection and audit evidence. If an intended operator is locked out, correct that user's
approved grant or roll back the specific new check through normal release controls; never open
the route to all authenticated users as an emergency shortcut. For financial visibility, test
response bodies, nested evidence, print and exports—not just hidden buttons or columns.

Close an item only when its policy, registration, assignment plan, backend checks, UI behaviour,
allowed/denied/cross-company tests, field-presence tests and deployment verification are linked
here. Until then, “permission-complete ERP” is not an accurate status.

## Related

- [Authorisation model and safe rollout](authorization.md)
- [Original feature retrofit](authorization-retrofit.md)
- [Research and design standard](research-and-design.md)
- [Open questions](../OPEN-QUESTIONS.md)
