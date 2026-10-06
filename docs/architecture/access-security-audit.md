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
| A3 | Parties [routes](../../backend/app/Modules/Parties/routes.php), Party/Customer/Supplier controllers and write requests | At the audit baseline, authenticated users could maintain Party, CustomerAccount, SupplierAccount and supplier sites. Four resource capabilities now guard their requests and twelve Actions; the PO inline supplier-site add control follows its capability. Backend and browser tests pass. | ADR 0084 field split implemented with body/Action tests and maintainer backfill; staging readership sign-off remains open. |
| A5 | Approvals [ApprovalRequestResource](../../backend/app/Modules/Approvals/Http/Resources/ApprovalRequestResource.php) (`show`, audit `index`, `inbox`, `decide`) | At the review baseline, `amount_base` and the nested currency evidence (original amount, rate, date, source) went to every viewer of a request — submitter, anyone with `approvals.history.view`, past deciders, the actionable approver — regardless of Purchasing/Estimating cost visibility. **Fixed 2026-10-03**: the figures follow the subject's own field policy plus a need-to-know exception (see below). | Finance/Purchasing to confirm the need-to-know exception (approver sees the amount of the request they must decide, without line prices). |
| A6 | Inventory [stock-moves, stock-layers, avco-pools, inventory-overview](../../backend/app/Modules/Inventory/routes.php) and their request classes | At the review baseline, stock quantities, locations and movement history were readable by every signed-in user (only valuation was projected), so `estimating.inventory_availability.view` could not actually withhold stock levels, and a physical counter always saw the book quantity. **Fixed 2026-10-03 ([ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md))**: `inventory.availability.view` (overview/summary), `inventory.stock.view` (layers, pools, position; also gives availability) and `inventory.movement.view` (ledger) are enforced at the request boundary; counters count blind. A migration first grants them to everyone who already used the screens (not to a count-only role). | Verify in staging with representative warehouse, sales, counting and finance users before the release; follow-up (separate): `negative-stock-override-requests` reads. |
| A7 | Core [forgot-password](../../backend/app/Http/Controllers/Api/V1/AuthController.php) | At the review baseline an unknown address returned a 422 "cannot find a user" while a known one returned 200, so the endpoint revealed which addresses have accounts. **Fixed 2026-10-03**: every address (and a broker-throttled repeat) gets the same 200 answer; tests assert identical bodies. | None; bearer-token expiry is a separate open policy item in the coverage map. |
| A4 | Inventory [ItemResource](../../backend/app/Modules/Inventory/Http/Resources/ItemResource.php) and [CategoryResource](../../backend/app/Modules/Inventory/Http/Resources/CategoryResource.php) | ADR 0084 now omits GL mapping keys without separate field read/manage authority on shared and nested responses. | Request/Action field-write checks and category form/detail/print projections implemented; staging readership sign-off remains open. |

A1–A3 were confirmed missing **write** capability boundaries at the audit baseline; A1 and A2
have since been retrofitted. That is not evidence that every user has the same UI controls. A4
is a confirmed unconditional response field, not a claim that an account
ID alone discloses balances. Request validation, company scoping and workflow rules may still
apply; a missing capability check is a separate issue.

## Coverage map and next inspection

| Area | First-pass state | Still to verify |
|---|---|---|
| Core Auth/Account/Currency/UoM/ExchangeRate | **Part 2 reviewed 2026-10-06:** Reviewed login/logout/recovery and self account controllers, requests and `UserResource`; Currency/UoM/FX list/show/includes and FX Actions. `AuthInformationFlowTest` + Auth/reference API tests prove credential secrecy, self-only mutation and foreign login/token refusal; no identity secrets or other-user grants are in Resources. A1/A7 retained. DELETE FX now explicitly gates in the controller before the Action. | Q36 token lifetime pending; self-only account access and global Currency/company UoM and FX reference reads remain intentional. No server print/export paths in this slice. |
| Accounting | **Part 2 reviewed 2026-10-06:** Reviewed all chart endpoints, three write Actions and `ChartOfAccountResource` parent/children includes; fiscal calendar has no HTTP API/history. Chart tests plus `MasterDataInformationFlowTest` prove shared lookup and foreign list/search/show exclusion. DELETE chart now explicitly gates in the controller before the Action. | ADR 0084 keeps chart identity/structure shared and protects cross-module mapping fields; future posting/lifecycle/history APIs remain unreviewed. No chart print/export endpoint or SPA chart screen exists. |
| Parties | **Part 2 reviewed 2026-10-06:** Reviewed Party/customer/supplier/site endpoints, twelve Actions and all Resources, including Party→account and account→Party includes and nested addresses. Existing write tests plus `MasterDataInformationFlowTest` assert company isolation and actual nested bodies. All four DELETE controllers now explicitly gate before their Actions. | ADR 0084 implemented: credit/control keys omitted without field read; terms stay readable; field writes gated in requests/Actions. FinancialMasterDataAccessTest asserts actual list/detail/nested/mutation bodies and foreign companies. No Parties print/export API or master-data SPA exists; staging assignments remain open. |
| Administration | **Part 2 reviewed 2026-10-06:** Reviewed member/role endpoints, all Actions, Resources and scoped history. Hydrated foreign targets now refused by six Actions; profile Action whitelists name/email. `AccessAdministrationActionIsolationTest` and existing API/isolation tests prove bodies omit credentials and unrelated/foreign history. | Q26 delegated administration remains unimplemented. Current company-wide administration is not unit delegation; staging actor classification/sign-off still required. |
| Approvals | **Part 2 reviewed 2026-10-06:** Reviewed subject types, policies, authorities/mine, delegations, history/inbox/show/decide/withdraw; all Actions/Resources and nested decisions. Five governance and two internal workflow Actions re-query scoped targets; decision eligibility now precedes state-changing preflight. `ApprovalInformationFlowTest`, engine/API/evidence/guard tests pass. Follow-up: delegated eligibility now requires active, credential-ready actor/principal; body/Action tests cover blocked and restored access. Demo personas now use the shared create-once member helper, reload database defaults before approval and preserve credentials on reruns; lapsed authority renews through its Action. Production eligibility and persona capabilities are unchanged. Delegation lists are involvement-scoped by ADR 0070, not pending Q35. | Q35 exception, history-reader ceiling access and free-text decision/closing reasons remain unchanged pending decision. No approval print/export API; future subjects must implement `canViewEvidence`. |
| Estimating | Original CRUD retrofit tracked separately; material preview, stock-mix, variance and cost-breakdown reviewed 2026-10-03: cost and availability are projected by capability and proven on response bodies (`MaterialPreviewApiTest`: `assertStringNotContainsString` on cost figures), cost breakdowns return 403 without `estimating.internal_cost.view`; approval evidence now follows `estimating.internal_cost.view` (A5) | **2026-10-06 (Part 1):** every write request guards its capability and every read request was classified — policy, history and variance reads need their own capabilities; the rate-dimension, application-rate, formulation, assembly(-group) and resource-rate list/show/resolve requests are open shared lookups on purpose (named in `OpenReadRoutesTest`), their commercial and cost fields projected by capability. Read from the request classes and the existing response-body tests, not a fresh exploit pass. Phase 4 adoption/quotation surfaces must be reviewed as they are built. |
| Imports | **Part 2 reviewed 2026-10-06:** Reviewed profile/stage/validate Actions, mappings/raw rows/errors and callers. No HTTP/console/job read/write/export API or Resources exists (`ImportStagingHasNoEntryPointTest`). Staging now re-queries the profile before reading mappings; `ImportProfileTenantIsolationTest` proves foreign input cannot be staged. | Future entry points still need capability, record authority and staged-row field policy. No import capabilities introduced solely for this inactive foundation. |
| Inventory | Existing operational and valuation slices tracked separately; valuation fields (`on_hand_value`, stock-move valuation, pool value) are projected by `inventory.valuation.view`; **there are no export, download or print endpoints** (printing renders data the screen already holds); A4 open; **A6 fixed (ADR 0083)** | **Negative-stock override request reads gated by workflow duty or `inventory.stock.view` (2026-10-06, A8)**; GL-field policy (A4); re-check when package-balance operations (ADR 0082) add routes — `PackageOperationsHaveNoEntryPointTest` fails the day an HTTP, console or job caller reaches them (their Actions check `inventory.package-state.*`). **Also 2026-10-06:** physical-count reads gated, and every Inventory write request guards its capability. |
| Numbering | Endpoint-by-endpoint allowed/denied tests exist; **by-id cross-company access to series, versions, rules, counters and counter raises proven unreachable (2026-10-03)**; the allocation log has no read API | History/allocation-log screen, when built, needs `numbering.view` plus a field review (it stores supplier/customer codes). **2026-10-06:** no Numbering request returns `true`; no numbering route is open to a no-capability user. |
| Purchasing | Existing document/commercial-cost slices tracked separately; packaged purchasing reviewed 2026-10-03: supplier-offer prices/currency/price basis are projected by `PurchasingCommercialAccess` and the offer Action requires manage + commercial access; package writes need `inventory.item.manage` at request and Action; the PO line preview needs create | **2026-10-06 (Part 1):** no Purchasing request returns `true`; PO, receipt, quality-disposition (incl. the nested line list) and item-supplier reads are denied to an unrelated user in tests, and the `commit-open-purchase-orders` route gained its request-layer guard. Future exports and any cross-module price evidence (approvals now covered, A5). |

An observed check is **not** an audit sign-off. [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)
still owns organisation/record scope; [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
still owns company PO policy. Neither should be silently decided by adding a role.

## Audit ownership (two agents in parallel, from 2026-10-06)

To finish the audit quickly, the remaining work is split by module so the two agents rarely touch
the same files. Each edits only its own coverage-map rows and work-queue items; shared files
(this tracker, STATUS, OPEN-QUESTIONS) are edited by inspecting the diff first.

| Part | Modules and work |
|---|---|
| **Part 1** | Inventory (incl. negative-stock override reads, ADR 0082 package routes, any export), Purchasing, Estimating, Numbering allocation log; the build rules in [authorization.md](authorization.md#build-rules-every-new-feature-follows) and `AGENTS.md`. |
| **Part 2** | Core/Auth, Accounting, Parties, Administration, Approvals, Imports; decision briefs for Q34, Q35, Q36, Q26; the staging verification inventory and sign-off template; A3/A4 field projections once Q34 is decided. |

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
- [x] **A3 financial fields:** ADR 0084 accepted and implemented; separate credit/terms/control
  authority, Resource projection and request/Action tests. Staging readership remains open.
- [x] **A4 GL fields:** ADR 0084 implemented across Item/Category and nested Resources,
  requests/Actions and category form/detail/print; negative field-presence tests accompany it.
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
- [x] **Action-level guards (2026-10-03):** every API write route now has a capability check inside its Action, through
  `App\Support\ActionAuthorization`, as well as at the request boundary: approval policies, authorities, delegations and the mode;
  the Estimating policies and variance reasons; the Inventory, Purchasing and Company settings; and the master data — purchase
  orders, item suppliers, items, categories, warehouses, locations and warehouse-location roles. A console command, job or
  integration is stopped as a request is. `ACTION_WITHOUT_GATE` in `WriteRouteGuardTest` is empty and must stay empty;
  `ApprovalGovernanceActionGuardTest` and `MasterDataActionGuardTest` prove each Action refuses a caller without its capability. The
  open-PO cutover commit (`purchasing.cutover.commit`) creates its orders through `CreatePurchaseOrder::forWorkflow`, because that
  workflow has already authorised its own actor under its own capability. Demo seeders, jobs and tests pass the actor explicitly.
- [x] **Administration, Numbering, Imports and Core Auth negative paths (2026-10-03):** foreign-company member/role and series/counter
  routes are unreachable by id (new isolation tests); forgot-password no longer enumerates accounts (A7); the Imports Actions have no
  entry point and a test keeps it that way. Open from this pass: bearer-token expiry.
- [x] **A6 stock-read capabilities (2026-10-03):** ADR 0083. Three separate read capabilities enforced on the overview, layers, pools and
  ledger (request boundary, before includes/filters), blind counting by role, SPA screen access aligned, and a grant migration that
  preserves existing screen holders. `StockVisibilityTest` (each surface × each capability alone and none, includes/filters, body
  checks), `StockVisibilityRolloutTest` (grants, idempotence, the count-only exception, per-company) and the blind-count test pass
  with the Inventory, Estimating, Purchasing, Support and Architecture suites. Deployment verification in staging is still to be done
  and recorded.
- [x] **A8 negative-stock override reads and write boundary (2026-10-06, Part 1):** list/show were open to every signed-in user and exposed
  shortage quantities; they now need a negative-stock duty or `inventory.stock.view` (`NegativeStockReadAccess`). The review also found that
  create/decide/review were guarded **only inside their Actions** (a 422 domain error, no 403 at the request layer), so the earlier summary
  "every write route is guarded at the request boundary and in its Action" was too strong: `WriteRouteGuardTest` accepts an Action gate
  alone. Their Form Requests now check the same capability. `NegativeStockOverrideRequestApiTest` covers each outsider (unrelated,
  availability-only, movement-only, valuation-only), each reader, and a stock viewer refused on create/decide/review; Inventory suite and
  architecture tests pass. **Sweep of Inventory (Part 1) done the same day:** six more write requests had the same gap and now check the
  Action's capability first — recalculation create (`.request`) and decide (`.approve`), cutover create, physical-count create, add-line and
  post; two tests that asserted a 422 for an unpermitted actor now expect 403 and validate shape as a permitted user. The inventory
  settings request was already per-field-group guarded. A second pass closed four more write requests (cutover batch attach and
  negative-opening request, counting a line, resuming regeneration) and the unguarded physical-count cancel route (new request class), and
  **gated physical-count list/show** (`PhysicalCountReadAccess`: a count duty, `inventory.stock.view` or `inventory.valuation.view`) — they
  were open to every signed-in user and returned counted quantities per item and location; the SPA rule gained `inventory.stock.view`. **Still to do (Part 2 modules and Estimating/Purchasing/Numbering sweep):** the
  remaining Form Requests that return `true` on a write (Accounting 2, Company 1, Parties 7 at the time of counting) and `WriteRouteGuardTest` now
  requires the request-layer guard too (`REQUEST_WITHOUT_GATE`, 2026-10-06): it found seven more Inventory/Purchasing write routes guarded only
  inside their Action — cutover validate, approve, commit, finalise, negative-opening approve, commit-open-purchase-orders and recalculation
  execute — each now has a Form Request checking the capability its Action enforces. The six Accounting/Parties/FX DELETEs it also found were
  closed by Part 2; the list is empty and must stay empty.
- [x] **Open-read sweep (2026-10-06, Part 1):** a user holding no capability was sent to every id-less API read; 28 answered 200.
  `tests/Feature/Support/OpenReadRoutesTest.php` now names each with a reason (15 shared reference lookups or the caller's own data, the
  estimating lookups whose cost fields are already projected, and the two settings endpoints whose only sensitive figure is projected) and
  lists **six still pending review**: `company` (registration/tax numbers — Core), `parties`, `customer-accounts`, `supplier-accounts`
  (Q34), `chart-of-accounts` (A2/Finance) and `approval-delegations` (Approvals) — those belong to Part 2 and the owner decisions, and
  each is removed from the pending list when decided. A new open read now fails the test (mutation-checked); so does a listed route that
  is no longer open. Limit: id-less routes only; `show` routes rely on each module's own denied tests.
- [ ] **Information-flow review (continuing):** complete the module inspections above and update this matrix with endpoint/Action/test
  references. New Inventory/Purchasing/Estimating features need their own check before sign-off.
- [ ] **Deployment verification:** enumerate each active company member, role, direct grant and
  service actor needing a new capability; verify effective access in staging before enforcement.
  Record who approved the map and the result. Re-run administrator permission sync. Do not grant
  a broad role merely to make a failing test or workflow pass.

### Part 2 evidence and staging sign-off (2026-10-06)

Code review covers every currently registered endpoint, Action and Resource in Core/Auth,
Accounting, Parties, Administration, Approvals and Imports. Existing allowed/denied write
coverage was reused, not retrofitted again. No new permissions, grants or financial projections
were introduced. Four gaps were fixed: hydrated foreign Administration targets, hydrated foreign
approval-governance targets, foreign import profile mappings, and ineligible approval preflight
cancellation; Administration profile Actions also reject credential/status changes by omission.
Internal stage opening/invalidation also re-query the scoped request. These enforce existing
tenant and operation boundaries, not Q26 organisational scope. The focused Part 2 regression run
passes **264 tests / 1,084 assertions**; explicit-path Pint and PHPStan pass. The throwaway database
is `erp_codex_part2` and is dropped after verification.

Read-only inventory: `docker compose exec -T app php artisan erp:audit-access-inventory > access-inventory.json`.
Run on staging, retain the output in restricted release evidence, and record its checksum.
The command reads every company, including unassigned roles, direct grants, effective role-plus-
direct capabilities and token-holder metadata; PostgreSQL enforces a read-only repeatable snapshot.
`AccessInventoryCommandTest` compares all relevant tables before/after and asserts no token secrets.
It neither registers permissions nor syncs administrators, grants access or updates last-use times.
A4 has no decided capability and A5 adds no new capability: its subject-field/workflow checks need
representative approval requests. Missing-capability flags indicate review, not an instruction to
grant all of them. `inventory.stock.view` also supplies availability at runtime; review that
implication before interpreting a missing explicit availability grant as lost access.
No service-account marker exists: classify **every member**, including actors without tokens,
and resolve unknown token/pivot actors and foreign-team assignments before sign-off.

Copy this template into restricted staging release evidence; this tracker records no live assignments:

| Sign-off field | Evidence to fill |
|---|---|
| Release / staging / company | Commit, environment, company ID/name, run time, inventory checksum/location |
| Assignment reviewer | Company owner + Finance/access administrator names and date |
| Actor classification | Every member: human/service; status; service owner, consumer and rotation plan |
| Approved map | Intended duties; role/direct capabilities; missing flags accepted or approved narrow grant change |
| Representative cases | Warehouse, sales, blind counter, Finance, administrator and each service consumer |
| API/body evidence | Allowed/denied and cross-company cases; nested includes, lookups and available print paths; approval amount/FX/reasons and authority/mine bodies |
| A1–A6 result | Rate/chart/Party maintenance; Q34 pending or decided field matrix; A5 workflow exception; A6 quantity/valuation and blind-count outcomes |
| Release procedure | Approved grant migrations applied; registration/admin-sync evidence separately recorded by operator; never run mutations as part of inventory |
| Exceptions / decision blockers | Q26/Q34/Q35/Q36 owner decisions, accepted missing capabilities, unresolved actors and release impact |
| Final sign-off | Owner/reviewer, date, PASS/BLOCKED, follow-up reference |

Inventory tooling is delivered; **staging verification is not done** and the Deployment verification
checkbox remains open. Q34 implementation waits for an owner-approved field matrix and ADR;
Part 2 will implement the decided A3/A4 projections, coordinating Inventory-owned surfaces
with Part 1 before editing them.

### Part 2 remaining-boundary sweep (2026-10-06)

The six remaining Part 2 DELETE routes (FX, chart, Party, customer account, supplier account,
supplier site) now check their existing capability in the controller before calling their already-
guarded Action. Their existing API suites prove allowed/denied deletion and unchanged records on
refusal. Part 1 is adding a separate HTTP-boundary ratchet to `WriteRouteGuardTest`; its six Part 2
exception rows have been removed in the shared working copy for Part 1 to land with that test.
The original Action ratchet remains empty. No capabilities or grant migrations were added.
Focused backend checks pass 187 tests / 771 assertions; explicit-path Pint and PHPStan pass.
The generated six clients, six 403 models and models barrel reflect the new documented denial
responses; API/UI TypeScript checks pass. The private `erp_codex_remaining` database is dropped.

`ApprovalInformationFlowTest` first reproduced a suspended principal's delegate receiving amounts
in their inbox; the fix makes actor/principal eligibility require an active account past temporary-
password restrictions. It proves no inbox/show evidence or decisions during suspension, invitation
or mandatory password change, including direct Action calls, and successful delegated approval
after reactivation. Existing maker/checker, amount ceiling, expiry and evidence rules remain.
The same test proves involvement-only delegation-list bodies; `OpenReadRoutesTest` now classifies
that existing ADR 0070 surface as open by design. The remaining five pending shared reads still
await the documented company/Finance policies; no Q26/Q34/Q35/Q36 recommendation was adopted.

The inventory command ran read-only against development as a deployment-mechanics smoke check:
one company, 12 members, five roles, no unresolved token actors and two unresolved assignment
actors. No assignment was changed. Restricted evidence is outside the repository; this is not
staging sign-off. A separate staging target and owner-reviewed actor/assignment map are still needed.

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

### Q35 document-review research refinement — 2026-10-06

The owner challenged total-only review. The [revised Q35 brief](../OPEN-QUESTIONS.md#q35--approval-evidence-exception-and-authority-ceiling-visibility)
compares official Business Central, Dynamics Supply Chain and Oracle Fusion evidence. It proposes
full-commercial request-specific PO review by default, with optional technical review for larger
companies, safe versioned evidence and separate ceiling readership. This remains an owner
choice; the current amount exception, ceilings and reasons are unchanged. No Purchasing code
or Part 1 coverage row was changed by this research.

### Q34 / A3–A4 accepted field split — 2026-10-06

The owner accepted [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md).
Credit limits and account mappings have independent read/change authority; payment terms stay
readable with separately controlled changes. Field managers receive minimum read. Requests and
all eight account/Item/Category Actions reject protected key presence without field management;
omission preserves values. Shared Resources project list/detail/lookup, nested cross-module and
mutation bodies. Category forms/detail/quick print honour field authority; no Parties/Item GL
print API or master-data screen exists. Chart reference identities remain shared.

The additive/idempotent migration registers five capabilities and backfills existing actual field
maintainers through company role/direct grants, without inventing roles or granting all old readers.
`erp:audit-access-inventory` now flags A3/A4 field duties. Review Finance/sales reader assignments,
legacy maintainers and service actors in staging using the sign-off template above; staging is
still unverified. Rollback retains additive grants because manual/pre-existing grants cannot be
distinguished safely. Q26, Q35 and Q36 remain undecided.

Evidence: `FinancialMasterDataAccessTest` checks denied/allowed fields, protected writes, non-HTTP
Actions, foreign companies and nested ItemSupplier consumers; `FinancialMasterDataRolloutTest`
checks direct/role grants, company isolation and idempotence. Browser evidence is recorded after
verification in the phase checkpoint.

## Related

- [Authorisation model and safe rollout](authorization.md)
- [Original feature retrofit](authorization-retrofit.md)
- [Research and design standard](research-and-design.md)
- [Open questions](../OPEN-QUESTIONS.md)
