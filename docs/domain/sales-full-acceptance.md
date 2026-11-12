[← Domain index](README.md)

# Full customer acceptance — backend slice 2

**Implemented locally, inactive (2026-11-11).** The screens are delivered (below) behind the quotation UI gate, which is off in production. No live duty registration or production permission holder. The **local-only** development preview and the `full` demo preset DO hold `sales.customer-decision.accept` (and view / evidence.record), guarded by `APP_ENV=local` plus `SALES_DEV_QUOTATION_DUTIES=true`, never in production; in the shipped catalogue the duties stay STAGED and ungranted. The owner authorised the
backend under the [brief's provisional defaults](../phases/phase-4-customer-acceptance-and-handover-design-brief.md#assumed-defaults-slice-2-owner-review-pending).
The earlier built/missing inventory in that brief is historical. Service preparation backend is now [delivered inactive in slice 3](sales-service-handover-preparation.md); slice 4 remains unimplemented.

## Semantics and storage

One case component accepts one full independently acceptable issued scope, selecting exactly one
option when it belongs to a choose-one group. Separate independent scopes use separate components.
There is no quantity or price editor. Consent never creates an Order, Job, reservation, invoice,
revenue, financial account or Opportunity outcome. CustomerAccount is not an acceptance prerequisite.

| Table | Authoritative facts |
|---|---|
| `customer_evidence_policy_versions` | Immutable fixed initial policy, version 1: written source, named representative, DOCUMENT_ON_FILE or CONFIRMED_BY_SELLER verification; verbal disabled. No policy editor/activation API. |
| `customer_acceptances` | One immutable fact per company/component; same case/customer/family as its issued revision and current written consent; actor, database recording instant, exact intent digest, pinned policy. |
| `customer_acceptance_lines` | One immutable link to the issued line per acceptance; the issued line retains exact quantity, UoM, settled money, currency, tax and FX evidence. No recomputation or duplicated monetary ledger. |
| `customer_scope_claim_heads` | One original-scope or choice-group anchor; capacity 1 means one whole entitlement, independently of the offered UoM/quantity. Zero-consumed baselines are created lazily and labelled BASELINE_CAPTURED. |
| `customer_claim_events` | Immutable full consumption events; sequence 1, delta 1. Inserts maintain the heads; no release or decrement path. |

All links are company-composite FKs. Database identity/lineage guards and deferred completeness
require acceptance, its line and every root/choice claim to commit together. Direct head mutation,
over-consumption, duplicate components/lines and UPDATE/DELETE/TRUNCATE of history are refused.
`down()` takes exclusive locks before checking and refuses while any evidence or baseline exists.
All constraints are on new empty tables, immediately validated; no historical-row violation needs
an ADR 0180 integrity-registry entry. Entitlement-consumption ledgers for partial pricing remain deferred.

## Decision and replay protocol

Both Preview and Record call `DecisionActor::require` via `CustomerDecisionAccess::decide` inside
the transaction, before authority/record locks or receipts. The fresh ACTIVE member needs
`sales.customer-decision.accept`, independent `sales.customer-decision.view`, and independent
quotation-family view without named delegation. Decision duties with limited scope fail closed;
foreign/hidden source identities are not-found. Party read follows the existing active-member,
company-scoped Parties rule, without adding a new Party duty.

Lock order: company access fence → existing Sales authority head → case → existing claim heads
in ascending id → revision → inserts. The authority head serialises lazy head creation and
existing withdraw/replace Actions, which already use that coordinator (ADR 0183/0164).
Preview creates no acceptance, baseline, policy or operation receipt. It returns exact issued
terms, an unchanged-terms statement and a ten-minute encrypted actor/company/intent-bound token.
It warns `DELIVERY_NOT_RECORDED` where there is no standing recorded PDF delivery, without making
delivery a consent prerequisite or disclosing delivery contacts.

Record uses the unchanged `CustomerDecisionOperation`: one per-actor request-key receipt, atomic
with all business facts. Exact receipt replay and matching component replay (even a fresh key or
another authorised person) precede mutable eligibility/token checks; changed intent conflicts.
Every replay first repeats current authority and source access, so suspension/revocation refuses.
A new decision requires current observation/case/claim generations, valid review, available offer,
and verified written consent. Expiry is checked under the locks using the company calendar day
and the issued expiry instant. No UTC-date or database-current-date substitution.

## API contract

All routes use `auth:sanctum`, Form Requests, Action guards and typed Resources. Duties remain
STAGED and ungranted in the shipped catalogue; only the local-only development preview and the `full` demo preset hold the accept duty (see the note at the top). A hand-created
permission row cannot bypass the manifest. An unauthenticated caller gets 401; inactive/limited
duties get 403; foreign/hidden identities get 404.

| Route (under `/api/v1`) | Inputs/result |
|---|---|
| `GET /quotations/{revision}/acceptance-preview` | `component_id`, `evidence_id`, `issued_line_id`, `expected_case_generation`, `expected_scope_generation`, optional nullable `expected_choice_generation`. Exact terms and opaque review. |
| `POST /quotations/{revision}/acceptances` | Same intent, `preview_token`, `unchanged_terms: true`, `Idempotency-Key` header. Returns `acceptance_id`, `case_id`, `replayed`; 201 for new request, 200 plus Idempotency-Replayed for receipt replay. |
| `GET /customer-acceptances/{acceptance}` | Safe immutable agreed scope, recording identity/time, case/evidence links and FULL_ONLY policy; no cost, margin, intent digest or internal tax/FX fingerprints. |
| `GET /customer-acceptances/{acceptance}/readiness` | Optional `path=SERVICE_JOB|GOODS_ORDER`; current assessment, separate from acceptance truth. |

`GET /quotations/{revision}/issued` additively returns nullable `scope_acceptance`, a list of
issued-line/scope identities with ACCEPTED, CLAIMED, AVAILABLE or UNAVAILABLE. It is null without
decision view and never identifies a competing acceptance. The case consent flag derives only
from accepted components whose family the caller may independently see. Generated client changes
are limited to these routes and the additive issued Resource.

409 codes: REQUEST_KEY_REUSED, DECISION_CASE_STALE, SCOPE_ALREADY_CLAIMED,
CHOICE_ALREADY_SELECTED, PREVIEW_TOKEN_STALE, DECISION_INTENT_CONFLICT. Business refusals
ACCEPTANCE_NOT_ELIGIBLE and QUOTATION_NOT_AVAILABLE are 422. Existing key/input codes are reused.
No hidden competing identity or quantity appears in a refusal.

## Readiness and rollout

Readiness now uses the [slice-3 source-specific assessment](sales-service-handover-preparation.md):
current service/contact/site facts and owning-domain next actions, with preparation_ready distinct
from release readiness. Overall NOT_READY remains honest while canonical conditions, service-cost
certainty, Inventory/Purchasing applicability and the Job receiver are undelivered. Goods remain
pending; unsupported manual/Assembly paths are refused. Consent remains valid while readiness is
outstanding; no financial clearance is implied.

Holder recipe (not executed): separately review administrative evidence recorders and named sales
acceptance approvers, their independent decision/Party/quotation reads and unrestricted decision
scope; overlap is permitted but never forced. Issuer may accept. No role-name inference, automatic
mandate expansion or production grant. In a local environment (`APP_ENV=local`, `SALES_DEV_QUOTATION_DUTIES=true`) the `full` demo preset and the development preview grant the duties so the real Actions can be demonstrated; elsewhere nobody holds the inactive duty. The slice-1 observation demo is unchanged.

## Screens

All behind the existing quotation UI gate (off in production) and the exact staged duties; nothing is registered, granted or seeded. Wording never says "Won".

- **Accept panel** on the issued-quotation screen (`/quotations/{revision}/issue`): per-scope badges (Accepted, Available, Claimed elsewhere, Not available) from `scope_acceptance`; one scope is chosen by radio; the customer message is chosen by search; the latest observation (the only one that counts) is shown and the server's consent rule is mirrored only to explain a disabled button. A person without the accept duty still sees the states and the reason. Needs `sales.customer-decision.view` and `sales.quotation.view` to see the panel, plus `sales.customer-decision.accept` to act.
- **Review sheet**: GET acceptance-preview, exact terms, unchanged-terms statement, warnings, a confirm box, then POST with one Idempotency-Key per (intent, review token): an uncertain failure keeps it, a changed review mints a new one, a stale review needs "Review again".
- **Acceptance detail** (`/customer-acceptances/{id}`): immutable terms, recorder/time, evidence and case link, claims, and the readiness panel showing NOT_READY honestly for SERVICE_JOB and GOODS_ORDER.
- **Customer Messages**: "Record a customer message" gains the quotation-side picker (the customer's issued quotations, their scopes, linked as components); it needs `sales.quotation.view`, and says so without it.

API limits the screens work around (no backend change): the issued read gives scope identities and state only, so line descriptions appear in the review, not in the panel; it does not say whether a scope belongs to a choice set, so the review asks as an ordinary scope first and as an alternative on PREVIEW_TOKEN_STALE (a preview writes nothing); the scope generation is 0 for a scope nobody holds.

## Completion and verification

- Semantics/research: accepted R6 research reused; technical choices in ADR 0183; all ten owner defaults remain provisional and are not ratified by [ADR 0187](../decisions/0187-record-phase-4-closure-decisions-d1-d16.md) or [0188](../decisions/0188-record-phase-4-closure-owner-decisions-second-response-2026-11-12.md).
- Authority/tenancy: Request + fresh Action checks, independent source read, limited denial, tenant FKs, no grants; race controls include suspension/revocation before decision and replay.
- Integrity: transaction/receipt, root and choice caps, append-only/truncate guards, deferred completeness, stale refusal; lifecycle and competing-consent/choice races tested in real sessions.
- API: typed Resources, stable codes, additive issued facts and generated client; no schema hand edits.
- Rollout: additive migration apply → rollback → re-apply passed; no activation or historical consent backfill.
- UI/lookup/browser/phone/accessibility: delivered (see Screens); browser and unit specs cover gate, states, 320/390 px, light/dark and keyboard.
- Demo: no new grant or acceptance seed, as required by inactive rollout.
- Deferred: partial quantity, late reconciliation, corrections, claim release, evidence upload, handover release and automatic WON.

## Related

- [Customer decisions](sales-customer-decisions.md) · [Duty matrix](sales-duty-matrix.md)
- [ADR 0183](../decisions/0183-record-full-acceptance-under-the-sales-authority-fence.md)
- [Backend acceptance journey](../journeys/record-full-customer-acceptance.md)
- [Brief and defaults](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
