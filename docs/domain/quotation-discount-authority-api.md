[← Domain index](README.md)

# Discount authority backend API and rollout

The [domain specification](quotation-discount-authority.md) and ADR 0163 govern semantics.
These adapters are implemented with STAGED duties; registering routes does not enable access.
The owner runs client generation and `docker/backend/codegen.sh --check` before integration.
No generated client is edited by this work.

## Configuration

All paths below are under `/api/v1/sales-discount-authority`. Configuration is company-wide;
restricted configuration scope refuses. Actions rehydrate the actor under security and authority
fences. A suspended/removed member or one requiring a password change cannot configure/read it.

| Method and path | Duty and purpose |
|---|---|
| GET `/{collection}` | Financial configure; paged version/band/assignment or event audit |
| GET `/{collection}/{id}` | Same; an absent/foreign/hidden row is 404 |
| POST `/references` | Financial configure; propose a versioned governed source |
| POST `/references/{reference}/approval` | Independent price-reference approve; proposer cannot approve |
| POST `/policies` | Financial configure; create an immutable version and all bands atomically |
| POST `/policies/{policy}/activation` | Financial configure; activate a reviewed version |
| POST `/assignments` | Financial configure; name one employee/band/purpose and optional role/unit |
| POST `/assignments/{assignment}/revocation` | Financial configure; append reasoned revocation |
| POST `/mode` | Financial configure; explicit OFF/SHADOW/ENFORCE transition |

Collections: `references`, `reference-approvals`, `policies`, `bands`, `policy-activations`,
`assignments`, `revocations`, `mode-events`. A reference approver can read the two reference
collections independently of financial configuration. Family-specific references and their
activation evidence additionally follow independent quotation view and family scope; a configuration
duty never reveals an inaccessible family's reference.

Every write carries `Idempotency-Key` (1–85 characters), `expected_generation` (from a configuration
read) and `reason`. A successful write returns `{data: {id, mode}}` with 200; `mode` is null except
for mode changes. An exact retry returns the original identity; changed intent under the same key
returns 409 `REQUEST_KEY_REUSED`. A stale generation returns 422 `AUTHORITY_STALE`. Unsupported
fields refuse. Reads return `{data: {generation, mode, page, per_page, total, records}}`, with
`page >= 1` and `per_page` 1–100 (default 25). Records contain explicit scalar whitelists, never
arbitrary evaluation snapshots or receipts. Timestamps are UTC ISO 8601; effective dates are local
business dates. Request rules/OpenAPI describe policy bands and reference/source evidence.

## Quotation evidence

| Method and path under `/api/v1/quotations/{revision}` | Guard and response |
|---|---|
| POST `/authority-references` | Prepare + view and family scope; `expected_generation`, Idempotency-Key; safe binding count only |
| GET `/discount-authority` | Independent authority evidence + view and family scope; current protected assessment |
| GET `/discount-authority/history` | Same; paged immutable evaluation summaries |

A configuration duty does not substitute for evidence view or record access. Ordinary preview,
readiness and quotation approval summaries remain amount/ceiling blind. Protected evidence returns
reference/final/reduction amounts, pinned base exposure, exact percentage, required rank and line
explanations; it carries no cost. The nullable `required_band` object exposes the matching immutable
band limits, enforcement rule and higher-approver rule within this protected quotation context;
reviewers do not need the financial configuration duty to inspect these limits. History explicitly projects the same fields rather than returning
raw stored snapshots. For an issued revision the current endpoint reads its pinned issue evaluation,
including the captured mode; it never recomputes issued evidence from current policy. Legacy issued
records without a pin return an explicit null. ENFORCE requires a captured family/scope reference before clearance. Binding
is explicit and inherits across revisions; incompatible established evidence refuses.

Existing request/decision/withdrawal/issue paths enforce current authority inside their transaction.
The Sales request input envelope (including frozen expiry/evaluation evidence) is immutable in the
database; status transitions remain available. A stale or expired pending request is ended when submitted again; a new request receives a fresh deadline.
Stale/expired evidence is not actionable in the shared inbox. The preparer can also withdraw a
stale pending request explicitly. Commercial editors cannot approve their own reduction. Reviewer
authorisation generations are captured at decision time: restoring a suspended or revoked reviewer
does not revive an earlier approval. Protected history remains readable with current evidence and
record access after the company switches authority mode off. Approval never replaces current issue permission, record scope, tax or mandatory financial controls.
The existing safe quotation approval summary also returns nullable `request.expires_at` and server-derived
`request.expired`; its `approval_needed`/`can.request` flags now reflect routine clearance and undeclared
reductions without disclosing authority figures. The configurable missing-evidence response remains 422 by default; only
`sales.discount_authority_hide_missing_evidence` changes it to 404.

## Demo and rollout

`DiscountAuthorityDemoSeeder` is called by the demo orchestrator after quotation/access fixtures.
It accepts only local/testing, Company 1 with the exact demo name/code, the PKR demo base currency,
and the named demo customer/service. It creates a labelled illustrative policy, five bands,
independent reference proposer/reviewer, governed ordinary/customer sources, named assignments,
an expired assignment and an unassigned employee. These are fixtures, not runtime authorisations.
No useful credential, grant, duty activation or company-mode change is made. Existing fixture
versions and people are never updated; reruns return without rewriting history. It never runs on
the development or production database as part of this task.

Real limits, people, trusted sources and mode activation remain owner decisions. All illustrative
values say DEMO ONLY. The default expiry is fourteen company-local calendar days, capped by
quotation validity. Owner review of proposed ADR 0164 and generated-client verification are
integration gates. Screens remain a separate workstream; their needs are in the
[screen handoff](quotation-discount-authority.md#screen-handoff).

## Backend completion checks

| Check | Disposition |
|---|---|
| Semantics and research | ADR 0163 and preserved R3/R9 research; concrete lock proposal in 0164 |
| Tenant, scope and duties | Company-composite evidence keys; configuration ALL scope; independent family view/evidence |
| Guard twice | Form Requests and fresh-actor Actions; engine subject hook also guards generic routes |
| Domain integrity | Common fences, exact-intent receipts, immutable versions/evaluations, fail-closed ENFORCE and pinned issue evidence |
| Database/rollout | Additive December 3 migrations, conservative downs and isolated cycle; no real grants or activation |
| API/client | Typed Resources and exported request schemas checked; client generation/drift check explicitly owner-run |
| UI, screen coverage, responsiveness, accessibility and lookups | Not applicable to this backend-only milestone; screens explicitly follow later |
| Tests | Sales/Approvals regression, focused authority/API/demo/race cases, unaffected consumers and named ratchets |
| Demo | Create-only, verified demo Company 1, no duty/holder/mode changes; production and other-company negative controls |
| Docs | Domain, API/rollout, journey, module READMEs and current STATUS/log updated together |
| CI handoff | Backend checks recorded in STATUS-LOG; generated-client verification and clean CI remain with owner |
| Shared checkout | Explicit owned paths only; no push, stash, reset, branch or worktree creation |

## Related

- [Quotation discount authority](quotation-discount-authority.md)
- [Sales authority](sales-authority.md)
- [Discount authority journey](../journeys/quotation-discount-authority.md)
- [Lock order](../decisions/0164-order-discount-authority-locks-before-quotation-and-approval-records.md)
