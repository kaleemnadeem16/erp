# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

No open question now blocks finalising the Phase 3 Material Resolution / Assembly Preview ADR.
Q25 is resolved by ADR 0057; Q26–Q28 remain non-blocking follow-ups for organisation scope and
reusable approvals rather than reasons to pretend those capabilities exist in Phase 3.

Q14 (backdated stock receipts) was resolved on 2026-09-01 and its final reviewed
consolidation is
[ADR 0040](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), with AVCO pool
scope remaining separately governed by ADR 0039.

Q20 (item divisibility and quantity increments) was resolved on 2026-09-25 — see
[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md). Its remaining package
boundary was subsequently resolved with Q17/Q19/Q22 by ADRs 0051–0053.

Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Q11 (stock valuation
method) resolved 2026-08-20 — see [ADR 0032](decisions/0032-per-item-valuation-method.md). Q12
(multi-supplier catalog) resolved 2026-08-20 — see
[ADR 0033](decisions/0033-item-suppliers-relationship.md).

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q10 — Sharing master data across a customer's own legal entities
**Blocks:** Nothing in Phase 1. Relevant once a customer wants more than one legal
entity/branch under one login.

`company_id` is the full tenant-isolation boundary
([GLOSSARY.md](GLOSSARY.md#company), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
not a legal-entity subdivision within one customer. Today, "multi-entity support" means another
row in `companies`, isolated from its sibling exactly as strictly as from an unrelated tenant —
the only planned bridge is user membership across `company_id`s
([multi-tenancy.md](architecture/multi-tenancy.md)'s planned tenant-switching UI), not shared
data. There is no construct for sharing a specific record — e.g. a trading partner in
[parties.md](domain/parties.md) — between two legal entities of the same real customer without
also exposing it to unrelated tenants.

Surfaced while deciding [ADR 0030](decisions/0030-unified-party-model.md) (a global, unscoped
`parties` table was considered and rejected as the wrong fix for this). Options when it becomes
real: a parent "account" grouping above `company_id`; per-record cross-company sharing grants; or
accept duplication and reconcile via an external key. Not decided — no second legal entity
exists yet to design against.

### Q16 — Recording the posting actor on stock movements
**Blocks:** Nothing yet — decide before sales despatch/consumption endpoints call
`PostInventoryMovement::issue()` in Phase 4/5.

`stock_moves` records no acting user. Today every movement is reached through a source document
or workflow that carries its own actor — a receipt, reversal or QA disposition, an override
request, a posted physical count, an inventory-cutover commit, or a recalculation execution
(`RecalculationExecution.executed_by`) — so the audit trail composes. But a plain issue/transfer
posted by a future endpoint would identify its actor only through its source document's
conventions. Decide whether the movement boundary
should stamp `posted_by` itself (one column, uniform trail) or whether source-document actors
remain the single source of truth.

### Q23 — Variable-content and catch-weight packages
**Blocks:** Variable-content receipts and package/container tracking; not fixed-content Phase 3

Do first-generation package records assume fixed contained quantity, or must receipt record an
actual quantity per container (catch weight, variable-length rolls, partly filled drums)? The
answer affects item packaging, receipt capture, labels and later container balances.

[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md) deliberately does not
answer this: its item-specific conversion factor is fixed by definition and cannot represent a
quantity that varies per physical container. Industry practice (SAP Catch Weight Management)
models catch weight as two independently-recorded quantities per transaction — a count/handling
unit and an actually-measured weight/volume — rather than a smarter conversion factor. That is a
different mechanism, not an extension of ADR 0050's table, and remains this question's to design.

### Q24 — Phase 5 material exceptions
**Blocks:** Nothing in Phase 3; decide before job material issue/return/scrap design

Which exceptions must ship in the first Phase 5 slice: customer-supplied/free-issue material,
approved substitutes, recoverable scrap, reusable remnants and returnable containers? These must
not be collapsed into ordinary purchased consumption because ownership, value and availability
differ.

### Q26 — Organisational and record-level access scope
**Blocks:** Department/site/warehouse/assigned-record visibility and delegated administration

What organisational structure should access follow: departments, branches, sites, warehouses,
cost centres, project teams or a composable organisation-unit hierarchy? Define whether users may
see own, assigned, unit or all-company records and whether one user can act in several units.
Define bounded delegated administration—what a department head may assign to subordinates—and
routine cross-department assigned/task-scoped access without creating one role per department pair.
Spatie Teams currently scopes assignments by `company_id`; it is groundwork, not this record-scope
model. See [authorization.md](architecture/authorization.md#teams-and-organisational-scope).

**Decision brief (2026-10-06; proposed, awaiting owner):** Options: keep company-wide records;
use Spatie Teams as organisation units (migrate assignments); or retain Teams for company and add
separate organisation memberships plus own/assigned/unit/company scopes. **Recommend the third**:
several memberships, one policy/query-scope definition applied to list/count/search/lookup/show/write,
and time-bounded task access. Delegated administrators get an explicit unit mandate and delegable
capability subset, never tenant/Finance administration merely through a role. Consequences: new
membership/scope records and audited delegation; existing grants need an approved company-wide
backfill before enforcement. Reject one role per department pair and direct grants as denials.
Basis: [ADR 0028](decisions/0028-dynamic-namespaced-permissions.md),
[ADR 0062 research](decisions/0062-company-member-and-access-administration.md#research-basis),
and the Odoo/Dynamics comparison below. No organisation or delegated-access model implemented.

### Q28 — Purchase-order approval policy
**Blocks:** Introducing PO submission/approval before operational purchasing relies on issuance

Which PO approvals are required by amount, currency, department/site, supplier/category or other
risk; may the creator submit, may an approver also issue, how many independent approvers are
needed, and what happens on rejection or draft revision? Today any authenticated caller reaching
the endpoint can create and issue a PO; DRAFT → ISSUED has no approval fact.

### Q33 — ERP-wide Notifications capability
**Blocks:** Any user-facing notification: approval requests and decisions, assignments, overdue and escalation events, module events

Notifications are a shared capability, not a feature of any one module, and are deliberately **not** built inside
the approval engine ([ADR 0070](decisions/0070-shared-configurable-approval-engine.md)): its inbox is pull-only until
this is decided. Before building it, research and record how mature ERPs handle in-app notifications; approvals and
assignments; overdue and escalation events; module-raised events; per-user and per-company preferences; delivery
channels (in-app, e-mail, mobile push, chat); grouping and de-duplication; deep links; permissions and company
isolation (a notification must never leak a record its recipient may not see); audit and history; and immediate versus
digest delivery. Those are prompts, not a predetermined architecture. Consumers waiting on it: approval requests and
expiries, overdue steps and escalation capabilities, recalculation and negative-stock reviews past their SLA, and the
purchase-order and quotation workflows.

### Q35 — Approval evidence exception and authority-ceiling visibility
**Blocks:** Finalising the access audit's information-flow review for approvals (not any build)

ADR 0070's approvals now show the routed amount and its currency evidence only to people the document's own module allows, plus whoever is
asked to decide or has decided that request (`ApprovalEvidenceAccess`). Confirm that exception — an approver sees the total of the request in
front of them without seeing the PO's line prices — and decide who may list per-person authority ceilings (`approvals.history.view` holders can
today). Free-text decision reasons are not filtered; decide whether that is acceptable.

**Decision brief (2026-10-06; proposed, awaiting owner):** Options: retain the current exception
and history-wide ceilings/reasons; remove the exception and require document-cost access;
or retain request-specific amount evidence while separately protecting ceilings and free text.
**Recommend the third:** actionable/past deciders keep the pinned request total and FX evidence,
never PO line prices through approval evidence; all-company ceilings require authority management
or a separate read capability, while `mine` stays self-only. Project decision/closing reasons to the same audience as that request's amount (subject evidence
readers, current actionable approver, recorded decider/principal); do not attempt numeric-text
scrubbing. Consequences: less context for non-financial auditors, explicit ceiling-reader rollout,
and consistent reason projection across list/show/inbox/decide/withdraw. Finance/owner must confirm
whether past-decider access persists after reassignment and which reason audiences are legitimate.
Basis: [ADR 0070](decisions/0070-shared-configurable-approval-engine.md) and existing
`ApprovalEvidenceVisibilityTest`; vendor approval limits govern eligibility, not our read policy.
No exception, ceiling or reason policy changed by this audit.

**Research refresh for the next owner decision (2026-10-06):** Reused ADR 0070's workflow
comparison. [NetSuite's official approval-limit guidance](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2395573.html)
routes a transaction by its amount and the approver's limit, with currency conversion;
[Dynamics field security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/security-data-entities#sensitive-data)
permits separate privileges for nonessential sensitive fields. These support amount-based
eligibility plus distinct data access, but neither prescribes our past-decider or reason audience.
Recommended scope: current actionable approver and recorded decider/principal retain the pinned
request total/FX evidence; ordinary history readers receive outcome/identity/time but no monetary
free-text reasons without subject evidence authority. Proposed `approvals.authority.view` permits
company ceiling lists; authority managers also read them, and `mine` remains self-only. History
readers who legitimately audit ceilings need explicit field-duty assignment/backfill approval.
A request author may insert figures into reasons, so hiding totals alone is insufficient; numeric
scrubbing cannot reliably remove all disclosures. This remains a proposal, with no Q35 code change.


### Q36 — Bearer-token lifetime and device sessions
**Blocks:** Any mobile client or integration using bearer tokens (Phase 7)

`sanctum.expiration` is null, so a bearer token never expires on its own (it is revoked on password change, reset, suspension and temporary
password). Decide the lifetime, whether tokens are per-device and listable/revocable by the user, and whether inactivity should end them.

**Decision brief (2026-10-06; proposed, awaiting owner):** Options: unlimited with revocation;
a fixed absolute expiry; or absolute plus inactivity expiry and managed device sessions.
**Recommend a 30-day absolute limit for human device tokens**, separate owner-approved shorter
service credentials, self list/revoke by device, and fresh sign-in to renew; defer inactivity expiry
until offline/mobile requirements are known. Consequences: existing old tokens expire at rollout,
users must reauthenticate, pruning is housekeeping rather than enforcement, and service owners
must plan rotation. Do not add an automatic refresh token which recreates unlimited lifetime.
Basis: [ADR 0005](decisions/0005-sanctum-authentication.md) fixes SPA cookies/mobile bearer modes;
[Sanctum expiry](https://laravel.com/docs/12.x/sanctum#token-expiration) supports global and
per-token expiry but specifies no ERP lifetime. Thirty days is a proposed product choice, not a
vendor standard. No expiry or device-management behaviour changed.

**Brief research supplement (accessed 2026-10-06):**
[Odoo record rules](https://www.odoo.com/documentation/19.0/developer/tutorials/restrict_data_access.html)
and [Dynamics XDS](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/extensible-data-security-policies)
separate record scope from operation rights; Odoo rules are default-allow when none applies,
whereas Dynamics applies constrained-table policies. Neither decides our organisation structure.
[Dynamics field/data-entity security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/security-data-entities)
protects nonessential sensitive fields separately and warns that imports/services need explicit
security; [Odoo field groups](https://www.odoo.com/documentation/15.0/developer/reference/backend/security.html#field-access)
provide a contrasting group-based field boundary. Both support server-side field enforcement;
our fields' sensitivity remains Finance's choice. [NetSuite approval limits](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2395573.html)
corroborates per-person amount authority already researched by ADR 0070; it does not establish
who may read ceilings or reasons. Odoo 19 page fetch timed out; its indexed official text and
existing ADR research were used, without treating unverified detail as policy.

---

## Answered---

## Answered

<a id="q34--finance-owned-field-visibility-for-accounts-and-credit-terms"></a>

Q34 was resolved on 2026-10-06 by [ADR 0084](decisions/0084-separate-financial-master-data-fields.md):
credit limits and account mappings have separate read/change capabilities; payment terms remain
readable with separately controlled changes.


Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.

<a id="q17--phase-3-commercial-package-and-costed-source-proposal-scope"></a>
<a id="q18--audit-requirements-for-planned-quantity-adjustments"></a>
<a id="q19--default-ranking-for-source-and-pack-proposals"></a>
<a id="q21--phase-4-selling-price-strategy-baseline"></a>
<a id="q22--physical-container-granularity"></a>
<a id="q25--material-resolution-permissions-and-sensitive-fields"></a>
<a id="q30--location-labels-are-ambiguous-across-warehouses"></a>
<a id="q31--a-failed-list-request-renders-as-an-empty-or-stale-list"></a>
<a id="q29--boolean-query-string-filters"></a>
<a id="q32--pickers-and-lookups-that-fail-to-load-read-as-empty"></a>
<a id="q27--shared-approval-workflow-contract"></a>

Q17, Q19 and Q22 were resolved on 2026-09-26 by
[ADR 0051](decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md),
[ADR 0052](decisions/0052-progressive-inventory-package-tracking.md) and
[ADR 0053](decisions/0053-explainable-inventory-package-selection-presets.md): Phase 3 proposes
mixed package use from internal inventory, physical tracking is progressive, and the first release
ships three explainable selection presets. Supplier replenishment remains Purchasing-owned.

Q18 was resolved on 2026-09-26 by
[ADR 0054](decisions/0054-risk-based-material-plan-variance-controls.md): every adopted variance
is audited, while versioned company/category/item policy escalates it to explanation, independent
approval or a hard block according to configured quantity, cost, waste and risk bands.

Q21 was resolved on 2026-09-26 by
[ADR 0055](decisions/0055-progressive-sales-pricing-and-controlled-formulas.md): Sales pricing is
independent from internal cost and tax, uses progressive SIMPLE/MANAGED/ADVANCED capabilities,
and advanced company formulas remain controlled, typed, versioned and explainable.

Q25 was resolved on 2026-09-26 by
[ADR 0057](decisions/0057-stable-material-resolution-capabilities-with-dynamic-assignment.md):
Material Resolution has stable action/field capabilities with company-configured roles and direct
grants; approval policy remains separate, while department and record scope remain Q26.

Q30 was resolved on 2026-09-28 by
[ADR 0059](decisions/0059-name-a-location-as-warehouse-and-location.md): a location is named
`warehouse / location` through one shared formatter, and the API always returns a location's
warehouse; codes stay unique per warehouse only.

Q29 was resolved on 2026-09-28 by
[ADR 0060](decisions/0060-accept-true-and-false-for-boolean-list-filters.md): the shared list-query
trait accepts `true` / `false` for any filter its request declares boolean, so the API no longer rejects
what the generated client sends.

Q31 was resolved on 2026-09-28 by
[ADR 0061](decisions/0061-retry-only-transient-failures-and-say-why-a-list-failed.md): a list retries
only transient failures, twice, and its error block names the cause and offers "Try again". The
question's first wording ("invisible") was corrected by measurement: the error was late and vague, not
absent.

Q32 was resolved on 2026-09-29 by
[ADR 0064](decisions/0064-pickers-report-a-failed-lookup-and-offer-retry.md): a picker fed by a request
says why it failed, in the tables' words, and offers "Try again" instead of reading as "no results".

Q27 was resolved on 2026-09-30 by
[ADR 0070](decisions/0070-shared-configurable-approval-engine.md): one shared, configurable engine — versioned
policies with typed conditions, counted distinct approvers per step, per-person authority ceilings, delegation within
the delegator's authority, pinned currency evidence and invalidation on change. Q28 keeps the company's own purchase-order
policy content (amounts, currencies, departments); the mechanism is the engine.
