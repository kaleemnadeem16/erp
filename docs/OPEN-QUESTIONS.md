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

### Q39 — Phase 4 R1: enquiry, deal and customer-onboarding boundaries
**Blocks:** finalising R1's Sales model and dependent quotation-recipient/acceptance design;
not research or the other agents' existing workstreams.

R1-A now has an **owner-reviewed proposed direction**, recorded in
[proposed ADR 0098](decisions/0098-hybrid-sales-opportunity-requirement.md): optional leads,
direct opportunities and one quotation workflow governed by FLEXIBLE/MANAGED/STRICT presets.
It is not accepted; historical migration exceptions, policy governance and issue/authority/retry
coordination remain flagged for resolution. Keep Q39 open.

R1-B1/B2 now have owner-reviewed direction in [proposed ADR 0099](decisions/0099-separate-quotation-issue-and-crm-association.md): draft relationship corrections and governed post-issue
CRM association separate from immutable issue linkage. STRICT temporary unlinking, closed-state
eligibility, association scope per family/revision and accepted/job-linked restrictions remain open.
R1-B3 now has owner-reviewed multiple independent acceptances and explicit alternative-family
groups in [accepted ADR 0100](decisions/0100-independent-quotation-scopes-and-alternative-groups.md).
The two forecast methods now have owner-reviewed direction in [accepted ADR 0101](decisions/0101-separate-accepted-business-and-remaining-forecast.md): MAINTAINED remaining estimate and
SELECTED_OFFERS, with accepted commitments separate and manual estimates unchanged on acceptance.
The [staleness](phases/phase-4-r1-b3-forecast-staleness.md) and
[FX](phases/phase-4-r1-b3-forecast-fx.md) review now records persistent provisional trust and
explicit-date SPOT publication as owner-reviewed direction
in [accepted ADR 0102](decisions/0102-govern-forecast-trust-and-explicit-valuation.md).
Ordinary later-date FX movement is now owner-reviewed as non-invalidating; used-rate corrections
require separate R4/R9 impact assessment. The [bounded B3 reconciliation](phases/phase-4-r1-b3-reconciliation.md)
records the final review: ADRs 0100–0102 accepted 2026-10-09 by owner authorisation, closing
bounded B3 business research only. Deferred delivery questions remain open; 0098/0099 stay Proposed.
C1 eligible Party-backed issue without CustomerAccount is resolved by [accepted ADR 0103](decisions/0103-issue-quotations-to-party-backed-prospects.md).
C2 valid acceptance before CustomerAccount, preparation and operation-level readiness are resolved
by [accepted ADR 0104](decisions/0104-separate-customer-acceptance-and-readiness.md). No universal
account-at-handover rule is accepted; precise cash/prepaid/non-credit gates belong to R6/R7 and
financial processing to later Phase 6.

**Current R1 closure blockers:** [six consolidated owner-review bundles](phases/phase-4-r1-decision-matrix.md)
U1 STRICT/legacy scope; U2 offer responsibility/eligible relationships; U3 organisation/person
identity, duplicate reuse/restore/conversion; U4 ownership versus visibility; U5 stages/outcomes/
partial wins/reopen; U6 initial CRM exposure. 0098/0099 remain Proposed pending their U1/U2
answers and cross-cutting access reconciliation. New recommendations are researched, not approved.
Detailed quotation structures/pricing/FX/lifecycle/partial scope/jobs/documents/report implementation
remain R2–R9 delivery gates, not reasons to keep R1 open after its foundations are approved.
[Additional primary evidence](phases/phase-4-r1-remaining-evidence.md) reuses sufficient previous
research. Review these choices together; then record ADRs and close R1/Q39 only when genuine
foundation choices are resolved or explicitly excluded. R2 is recommended next, not begun.

Record accepted answers in ADRs and remove only resolved questions; do not close all Q39 or R1
because one direction was reviewed.

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Q11 (stock valuation
method) resolved 2026-08-20 — see [ADR 0032](decisions/0032-per-item-valuation-method.md). Q12
(multi-supplier catalog) resolved 2026-08-20 — see
[ADR 0033](decisions/0033-item-suppliers-relationship.md).

### UI follow-up — header avatar hover contrast

The approval workspace audit (2026-10-07) found the existing light-mode account avatar's
`hover:opacity-90` can blend white text / primary blue to roughly 4.35:1 on the header. This is
outside the approval workspace and the approved palette was left unchanged. Review the shared
header's hover treatment in its own adoption pass; it does not block resting-state approval UI.

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
([GLOSSARY.md](GLOSSARY.md), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
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
**Blocks:** Remaining record-scope slices (see below) and delegated administration; the inventory-by-warehouse slices are built

What organisational structure should access follow: departments, branches, sites, warehouses,
cost centres, project teams or a composable organisation-unit hierarchy? Define whether users may
see own, assigned, unit or all-company records and whether one user can act in several units.
Define bounded delegated administration—what a department head may assign to subordinates—and
routine cross-department assigned/task-scoped access without creating one role per department pair.
Spatie Teams currently scopes assignments by `company_id`; it is groundwork, not this record-scope
model. See [authorization.md](architecture/authorization.md#teams-and-organisational-scope).

**Decided in part (2026-10-06, [ADR 0089](decisions/0089-organisation-units-and-record-scope.md)):** the owner chose organisation units as a
company-defined tree, inventory by warehouse as the first slice, and delegated administration later. Built: units, memberships, warehouse
ownership, the "only my units" limit on an assignment, one resolver, enforcement on stock layers, the ledger and physical counts, and (second slice, 2026-10-07) negative-stock requests, recalculation and cutover — every inventory surface now has a declared scope status. **Still open
(each its own slice):** purchasing documents (needs a
creator and ship-to recorded and a backfill first), Estimating availability, approvals; a warehouse-limited inventory overview and AVCO pool view;
own-record and assigned-record scopes; time-bounded task access; delegated administration (a unit head assigning access to their people). The
original brief and research follow.

**Decision brief (2026-10-06; historical — adopted in part by ADR 0089, which chose the third option; the "no model implemented" sentence below was true only on that date):** Options: keep company-wide records;
use Spatie Teams as organisation units (migrate assignments); or retain Teams for company and add
separate organisation memberships plus own/assigned/unit/company scopes. **Recommend the third**:
several memberships, one policy/query-scope definition applied to list/count/search/lookup/show/write,
and time-bounded task access. Delegated administrators get an explicit unit mandate and delegable
capability subset, never tenant/Finance administration merely through a role. Consequences: new
membership/scope records and audited delegation; existing grants need an approved company-wide
backfill before enforcement. Reject one role per department pair and direct grants as denials.
Basis: [ADR 0028](decisions/0028-dynamic-namespaced-permissions.md),
[ADR 0062 research](decisions/0062-company-member-and-access-administration.md#research-basis),
and the Odoo/Dynamics comparison below. (On 2026-10-06 no organisation or delegated-access model was implemented; organisation units and warehouse record scope now are — see the status paragraph above.)

**Research supplement (accessed 2026-10-06; relocated here from an earlier block it was filed under by mistake):**
[Odoo record rules](https://www.odoo.com/documentation/19.0/developer/tutorials/restrict_data_access.html)
and [Dynamics extensible data security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/extensible-data-security-policies)
separate record scope from operation rights; Odoo rules are default-allow when none applies, whereas Dynamics applies
constrained-table policies. Neither decides our organisation structure. Both enforce on the server, and Dynamics warns that
imports and services need explicit security. [Odoo field groups](https://www.odoo.com/documentation/15.0/developer/reference/backend/security.html#field-access)
and [Dynamics field/data-entity security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/security-data-entities)
are the contrasting field-level boundary, already applied here by ADR 0084 and ADR 0087. Odoo 19 page fetch timed out; its
indexed official text and existing ADR research were used, without treating unverified detail as policy.

### Q28 — Purchase-order approval policy
**Blocks:** Introducing PO submission/approval before operational purchasing relies on issuance

Which PO approvals are required by amount, currency, department/site, supplier/category or other
risk; may the creator submit, may an approver also issue, how many independent approvers are
needed, and what happens on rejection or draft revision? *(2026-10-08: the mechanism half is resolved — the shared approval engine, [ADR 0070](decisions/0070-shared-configurable-approval-engine.md); companies define policies and limits and PO approvals run through it. This question now covers only which default policy a company should start with.)*

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

### Q37 — Two-factor, step-up and mobile re-authentication
**Blocks:** Nothing built; needed before a mobile client or integration handles sensitive data in production (Phase 7)

The owner (2026-10-06) wants stronger sign-in researched properly later: **two-factor authentication that a company can make
mandatory or optional**, policies that require signing in again (every time, or after an interval), fingerprint/biometric sign-in on mobile,
and the like. [ADR 0088](decisions/0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md) leaves the places for them
(a sign-in requirement registry, a token policy, per-token authentication records) and fixes none of the decisions. Needs a bounded
primary-source comparison of mature ERPs before design ([research rule](architecture/research-and-design.md)): which factors (authenticator
app, passkeys/WebAuthn, e-mail or SMS codes), what a company can configure and per whom, recovery and lost-factor handling, how a pending
sign-in is represented and completed (the provisional 401 `challenge` shape), step-up for sensitive actions, device trust, and whether
service credentials for integrations are separate. Also open from the same review: a security-event audit trail for sign-ins, failed
attempts and device changes.

---

## Answered---

## Answered

<a id="q35--approval-evidence-exception-and-authority-ceiling-visibility"></a>

Q35 was resolved on 2026-10-06 in two steps: [ADR 0085](decisions/0085-request-specific-document-review-for-approvals.md)
(what an approver inspects: the full purchase order by default, optional technical-only review) and
[ADR 0087](decisions/0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md) (everyone's
spending limits need `approvals.authority.manage` or `approvals.authority.view`; person-written reasons
follow the evidence, plus `approvals.reason.view` for an auditor and the author's own words).

<a id="q36--bearer-token-lifetime-and-device-sessions"></a>

Q36 was resolved on 2026-10-06 by [ADR 0088](decisions/0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md):
every bearer token expires after 30 days (no refresh token), people list and sign out their own devices, expired tokens are pruned daily,
and sign-in has seams for stronger authentication (opened as Q37).

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
