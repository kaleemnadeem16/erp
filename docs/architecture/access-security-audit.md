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
| A1 | Core [exchange-rate routes](../../backend/routes/api/exchange-rates.php), [controller](../../backend/app/Http/Controllers/Api/V1/ExchangeRateController.php) and store/update requests | At the audit baseline, any authenticated user could create/update/delete rates. The `currency.exchange-rate.manage` HTTP/Action retrofit is implemented in the current slice; integration-test verification is pending the unrelated packaged-purchasing migration repair. | Assign a dev maintainer through the app and rerun API tests. Separate approval/history remains a later policy decision. |
| A2 | Accounting [routes](../../backend/app/Modules/Accounting/routes.php), [chart controller](../../backend/app/Modules/Accounting/Http/Controllers/Api/V1/ChartOfAccountController.php) and create Action | Authenticated users can create, alter/reparent and archive chart accounts without a chart-maintenance capability. | Finance owner defines chart-maintainer authority, lifecycle separation and any sensitive read boundary before enforcement. |
| A3 | Parties [routes](../../backend/app/Modules/Parties/routes.php), Party/Customer/Supplier controllers and write requests | Authenticated users can maintain Party, CustomerAccount, SupplierAccount and supplier-site master data without resource capabilities. Customer terms, credit limit and ledger account IDs are writable. | Define separate operational master-data powers and any Finance-only field policy; test Actions as well as HTTP. |
| A4 | Inventory [ItemResource](../../backend/app/Modules/Inventory/Http/Resources/ItemResource.php) and [CategoryResource](../../backend/app/Modules/Inventory/Http/Resources/CategoryResource.php) | GL account IDs are returned unconditionally on shared authenticated reads. This is an established open decision, not a newly introduced regression. | Finance decides whether those IDs are sensitive and who may edit/view them; then project fields and guard writes consistently. |

A1–A3 are confirmed missing **write** capability boundaries, not evidence that every user has
the same UI controls. A4 is a confirmed unconditional response field, not a claim that an account
ID alone discloses balances. Request validation, company scoping and workflow rules may still
apply; a missing capability check is a separate issue.

## Coverage map and next inspection

| Area | First-pass state | Still to verify |
|---|---|---|
| Core Auth/Account/Currency/UoM/ExchangeRate | A1 confirmed; shared reference lookups exist | Map every write/read, session and recovery boundary; test FX allowed/denied paths. |
| Accounting | A2 confirmed | Chart write Actions, reads, account-selection lookups, history and lifecycle rules. |
| Parties | A3 confirmed | Each Party/account/site Action, field projections, cross-company and credit/terms changes. |
| Administration | Capability and anti-escalation checks observed; existing API tests | Full member recovery, grant/revocation, self-lockout and audit-history negative-path review. |
| Approvals | Assignment/capability checks observed | Check whether approval `amount_base` and evidence reveal Purchasing commercial facts to users lacking `purchasing.commercial-cost.view`; approval authority is not a substitute for field policy. |
| Estimating | Original CRUD retrofit tracked separately | Review new material/adoption and cost surfaces as built, including non-HTTP Actions. |
| Imports | No independent HTTP controller in this pass | Verify each consumer's staging, commit, background actor and per-record authority. |
| Inventory | Existing operational and valuation slices tracked separately; A4 open | Review newly added routes, exports/print, and GL-field policy after Finance decision. |
| Numbering | New API shows capability/Action checks | Run endpoint-by-endpoint allowed/denied, cross-company, history and field review. |
| Purchasing | Existing document/commercial-cost slices tracked separately | Review new packaged-purchasing endpoints and future exports, including cross-module price evidence. |

An observed check is **not** an audit sign-off. [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)
still owns organisation/record scope; [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
still owns company PO policy. Neither should be silently decided by adding a role.

## Risk-ordered work queue

- [ ] **Policy decision:** product and Finance identify actual FX, chart and Party maintainers,
  separate duties that must not be bundled, and decide which financial/master-data fields need
  narrower read or write access. Record the decision in an ADR/domain doc, using existing research
  first. Do not infer it from demo roles.
- [ ] **A1 FX slice:** `currency.exchange-rate.manage` is registered and checked at HTTP and
  non-HTTP write boundaries, while authenticated lookup remains open. Complete integration tests
  after the other agent's migration is fixed; then grant an actual dev maintainer through Access
  Administration and verify effective access. Future rate import and calculation effects still
  need their own tests/review.
- [ ] **A2 chart slice:** follow the same sequence, including reparent/archive and account lookup
  uses; separate Finance field controls if the policy calls for them.
- [ ] **A3 Parties slice:** review Party, customer, supplier and site as distinct operations; apply
  the agreed capabilities and field policy to request, Action, response and existing UI.
- [ ] **A4 GL fields:** implement the Finance decision for Item/Category edits and every list,
  detail, lookup, print and export projection; add negative field-presence tests.
- [ ] **Information-flow review:** test approval evidence against Purchasing cost visibility,
  then complete the module inspections above and update this matrix with endpoint/Action/test
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
