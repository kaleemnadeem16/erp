[← Domain index](README.md)

# Service handover preparation — inactive backend slice 3

**Implemented locally, 2026-11-11.** Preparation only. No handover UI, activation, permission holder,
demo grant, Job receiver, release, stock commitment or financial effect. [ADR 0184](../decisions/0184-prepare-pending-service-handovers-without-release.md)
records the local choices; the brief's owner defaults remain provisional.

## Meaning and readiness

The preparation registry admits only a genuine FULL_ONLY accepted SERVICE_ITEM scope for
SERVICE_JOB preparation. MANUAL, Assembly and goods never fall back to a Job. Goods readiness
stays NOT_READY with HANDOVER_PATH_UNSUPPORTED because no Order target exists; preparing a goods
plan is refused. Unknown paths are refused with that same stable reason code.

Readiness now inspects current service Item, contact and site facts under shared row locks:

| Fact | Current rule / supplier |
|---|---|
| Service source | Active, non-deleted, non-stock SERVICE Item; Inventory resolves a changed/unavailable source. Frozen agreement is never repriced. |
| Location | Sales declares REMOTE or ON_SITE as operational context, without changing customer-agreed scope. |
| Contact | Parties supplies a current contact of the accepted customer, with email or phone. |
| Site | ON_SITE needs that customer's active, non-deleted site; REMOTE does not invent a site. |
| Completion conditions | Sales must deliver canonical versioned source evidence; none exists yet. A plan never fabricates customer conditions from a local checkbox. |
| Cost certainty | Sales service-cost evidence is currently UNKNOWN, never zero. |
| Material/warehouse and supplier effects | Inventory/Purchasing applicability is UNVERIFIED; a service type alone does not prove verified NONE. |
| Target | Jobs must deliver and verify the receiver under separately authorised slice 4. |

The assessment returns `missing` codes and `next_actions` with fact, owning domain and action.
`preparation_ready` means current operational selections are complete, not release clearance.
Overall `status` remains NOT_READY while any release prerequisite is absent; this slice cannot
truthfully produce READY because the completion, cost, owning-domain applicability and receiver
contracts are not delivered. No CustomerAccount or universal warehouse/site rule is invented.
Preparing a pending plan is legitimate; a selected unavailable source/contact/site is refused.
A missing unselected fact can remain pending. Consent stays valid throughout.

## Persistence and conservation

| Table | Immutable evidence |
|---|---|
| `handover_plans` | One per company/acceptance, path policy SERVICE_PREPARATION.1, preparer/database timestamp, exact operational intent and private encrypted preview token. |
| `handover_allocations` | One full accepted line per plan, unique across plans; full_scope_units=1 is whole-entitlement accounting, not a fake operational Job quantity. |
| `handover_stages` | One INITIAL SERVICE_JOB stage in PREPARED state, with no target id or release receipt. |
| `handover_baselines` | One initial baseline, exact immutable issued-line reference, same-Party contact/site references and permitted operational snapshot. |
| `handover_readiness_evidence` | The explicit pending assessment at preparation, separate from a current readiness read. |

The baseline pins exact accepted commercial terms through the immutable issued-line link;
there is no new monetary ledger or recalculated amount. Job-facing scope contains deliverable
identity and exact accepted description, operational instructions/contact/site, and **null**
measure, quantity and undelivered completion conditions. Commercial quantities, prices, tax/FX,
costs and margins are withheld. Null never means a fabricated quantity of one or complete zero cost.
The database forbids extra fields in this operational snapshot and incomplete readiness JSON.

Composite company FKs and checked parent/Party links bind plan → accepted line → stage → baseline.
A deferred guard requires all five records to commit together. Every table refuses UPDATE,
DELETE and TRUNCATE, including empty/cascading truncation. Rollback locks all tables first and
refuses populated storage. All new-table constraints validate immediately; there are no historical
violations to register. No target-table migration or mutation occurs in Jobs.

## Transactions, tokens and replay

Preview and Prepare check staged `sales.handover.prepare` plus independent acceptance and
quotation view. Plan reads deliberately reuse acceptance-view rules; no new handover-view duty
is activated. Unsupported limited decision/prepare scope fails closed. Hidden/foreign references
are not-found; basic independent quotation duty is checked before source existence lookup.

Inside each transaction: DecisionActor (access fence and fresh ACTIVE member/duties) → Sales
authority head → acceptance/case/ordered claim/revision rows → shared source/contact/site rows.
This is the same lifecycle coordinator as slice 2. Withdraw/replace always refuse once consent
stands, regardless of whether preparation wins the race first. No approval row is needed here.

The ten-minute opaque token binds actor, company, acceptance, immutable terms, normalized inputs,
current readiness/source/contact/site facts and the company calendar day. Changed context,
selected acceptance or readiness requires PREVIEW_TOKEN_STALE and re-review, never silent refresh.
Token and digests are private on stored plans; detail returns neither. No cost fingerprint is exposed.

CustomerDecisionOperation is reused unchanged (`handover.prepare`): per-actor key/hash/receipt,
atomically committed with the aggregate. Current authority and source access precede receipt and
matching-business replay; suspension or duty loss refuses both. An exact matching fresh key or
employee returns the same plan. A different full allocation conflicts with SCOPE_ALREADY_CLAIMED;
changed payload under one key is REQUEST_KEY_REUSED. Replay does not rewrite historical readiness.

## API and rollout

All paths are under `/api/v1`, `auth:sanctum`, Form Request plus Action guards, typed Resources.

| Route | Contract |
|---|---|
| `GET /customer-acceptances/{acceptance}/handover-preview` | Optional `path` (SERVICE_JOB), `location_kind` (UNKNOWN/REMOTE/ON_SITE), nullable `contact_id`/`site_id`, optional `instructions` (max 2000). Returns normalized inputs, permitted operational fields, readiness/owners, withheld field categories, token and expiry. No writes or capacity hold. |
| `POST /customer-acceptances/{acceptance}/handover-plans` | Same operational inputs, preview_token and Idempotency-Key. Returns plan_id/case_id/replayed; new request 201, receipt replay 200 plus Idempotency-Replayed. No caller-selected terms, readiness assertion, actor, target or allocation. |
| `GET /handover-plans/{plan}` | Immutable plan, allocation/stage/baseline/source identities, permitted operational snapshot, historical readiness and withheld categories; release_performed=false. |
| Existing `GET /customer-acceptances/{acceptance}/readiness` | Accepts the same optional operational context; returns current missing-fact next actions and preparation_ready additively. |

Reasons: HANDOVER_PATH_UNSUPPORTED / HANDOVER_NOT_READY (422), PREVIEW_TOKEN_STALE /
SCOPE_ALREADY_CLAIMED / REQUEST_KEY_REUSED (409), standard key/input refusals reused. Limited or
missing duty is 403; missing/foreign/hidden identities 404. No competing plan id or quantity leaks.
Generated client changes belong only to these routes and the updated readiness schema.

Duty/holder recipe, not executed: separately review operational preparers with independent
acceptance/Party/quotation reads and unrestricted preparation scope. `sales.handover.prepare`
is STAGED, absent from registration, automatic grants, dev preview and demo grants. The accepted
`sales.handover.view` name remains specified; reading uses the owner-requested acceptance rules.
Release duty/approvals, source completion/cost/applicability adapters and Jobs ownership stay separate.

## Completion and verification

- Accepted research reused; local choices/rejected alternatives in 0184; defaults appended to the brief.
- Fresh Request/Action authority, tenant and source isolation, limited denial, independent reads and suspended/revoked replay covered.
- Real two-session controls: concurrent preparation, both lifecycle orders, authority change, contact becoming unavailable; one full allocation only.
- Raw row/truncate/JSON guards, complete-aggregate rollback, forced final-evidence failure, populated rollback refusal and migration cycle checked.
- API Resources, stable codes and generated-client drift checked; no generated file edited by hand.
- No UI/phone/browser work applies to this backend-only slice. Existing acceptance screens are owned separately.
- No demo grant or seed is added while preparation is inactive. No scheduler, notification, accounting writer or new money field.
- Deferred: slice 4, successor baselines, amendments, holds, partial/split/mixed/Assembly/manual/goods paths and source/receiver release certification.

## Related

- [Operational handover](sales-operational-handover.md) · [Full acceptance](sales-full-acceptance.md)
- [Prepare journey](../journeys/prepare-service-handover.md)
- [Brief/defaults](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
- [0184](../decisions/0184-prepare-pending-service-handovers-without-release.md)
