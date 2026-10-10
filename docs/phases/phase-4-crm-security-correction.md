[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# CRM security correction — urgent implementation handoff

**2026-10-10 · READY FOR IMPLEMENTATION HANDOFF; NOT IMPLEMENTED.** Owner requested this
specification. Existing accepted authority rules govern. A/B/C business directions are now accepted
in ADRs 0111–0113, but their separate correction delivery is not included in this security slice. Planning owns this file; the designated implementation agent owns code,
verification and delivery. Coordinate scheduling through the owner before changing shared files.

## Contract and demonstrated defects

ADRs [0028](../decisions/0028-dynamic-namespaced-permissions.md),
[0089](../decisions/0089-organisation-units-and-record-scope.md),
[0105](../decisions/0105-support-generic-party-identity-and-conversion.md) and
[0106](../decisions/0106-separate-sales-ownership-and-access.md) apply. Authority is capability plus
its relevant scope; ownership grants nothing; unsupported restricted Sales modes fail closed.

1. `backend/app/Modules/Sales/Support/SalesAccess.php::may` checks the requested capability but
   resolves `RecordScope` against `PermissionVocabulary::all()`. An unrestricted stage/numbering
   grant therefore widens separately restricted opportunity/lead duties. Resolve the requested
   duty alone. The shared additive resolver is correct and must not be globally rewritten.
2. `backend/app/Modules/Parties/Support/PartyCandidateAccess.php::mayAsk` checks capabilities only.
   Sales registers lead/opportunity management via `SalesServiceProvider::boot`; candidate search
   can consequently return identities to callers whose only relevant grants are restricted Sales.
   The same flaw reaches `CandidatePartyRequest::authorize` and `FindPartyCandidates::__invoke`.

Source-inspected, not runtime-tested by the planning agent. No application suites were run.

## Exact boundaries

| Surface / code | Required treatment |
|---|---|
| `SalesAccess::may/require` | Capability plus scope of that exact duty. NONE or unsupported restricted scope refuses; unrelated unrestricted grants cannot rescue it. |
| `SalesAccess::mayRead/requireRead` | Preserve existing legitimate read alternatives: pipeline view, lead/opportunity manage, assign, close. Each alternative must independently pass its own scope. Stage/numbering alone never grants pipeline access. |
| `SalesAccess::mayChoosePeople`, `SalesOwnerLookupRequest`, `SalesOwnerController` | Preserve existing choosing duties, evaluate each with its own scope; no member results/counts from a restricted-only caller. |
| `SalesRequest` and all subclass Requests | Apply corrected guard before query/mutation; every mutation Action uses the same corrected duty guard. Direct Actions remain protected without HTTP. |
| Lead/Opportunity controllers and Resources | Lists, search/filter/sort, pagination counts, direct reads and includes (Party, lead/deals, notes/history/actors) inherit the same denial. No unguarded aggregation or alternate projection. |
| `PartyCandidateAccess::mayAsk/require`, provider registration | Module-owned search eligibility must include the registering workflow's scope semantics, not just its permission string. Prefer a guarded eligibility predicate/adapter over coupling Parties to Sales internals. |
| `CandidatePartyRequest`, `FindPartyCandidates`, `PartyCandidateResource` | One eligibility contract at Request and Action; denied probes disclose no id/name/code/kind/status/match indicators or result counts, including email/phone/tax/registration probes. |
| Archived candidate search / restoration | Preserve existing independent Party maintenance authority and restoration duty; a Sales grant alone does not allow archived discovery or restoration. |

An unrestricted grant for the SAME relevant duty still wins over a restricted grant for that duty.
An unrestricted permitted read alternative may legitimately permit reading without authorising a
separately restricted write. Never combine permission from one duty with scope from another.

Independent `parties.party.manage` authority remains governed by the existing Party boundary;
a restricted Sales grant does not revoke legitimate Party access. Do not apply Sales fail-closed
behaviour to every Party workflow, invent Party unit scope, or broaden Party maintenance through
Sales registration. The inspected Party vocabulary confirms this duty name.

## Regression and acceptance matrix

| Scenario | Expected evidence |
|---|---|
| Restricted opportunity manage + unrestricted stage, then numbering, tested separately | Opportunity create/update/stage/note Action and HTTP paths refuse; no mutation/evidence written. |
| Restricted lead manage + unrelated unrestricted Sales duty | Intake/update/link/decision refuse through HTTP and direct Actions. |
| Restricted assign/close + unrestricted read or manage | Read remains permitted by its legitimate grant; reassignment/closure refuses. |
| Restricted-only pipeline reader, including no-unit member | Lead/opportunity lists, direct reads, nested history/notes and owner lookup refuse where relevant; no counts/identities disclosed. |
| Restricted Sales searcher + unrestricted stage/numbering | Candidate Request and direct Action refuse every supported probe; no existence-dependent payload. |
| Relevant duty restricted by role + same duty unrestricted directly or through another role | Relevant operation permitted. Include inverse role/direct arrangements. |
| Independent authorised Party maintainer + restricted Sales | Candidate lookup remains allowed; archived discovery follows Party restoration authority. |
| Unrestricted Sales searcher without Party maintenance | Eligible active candidates only; no restoration/archived discovery or financial-role mutation. |
| No grant, wrong company id/probe, revoked grant | Established denials and company isolation preserved. Cross-company identifiers never returned. |
| Unrestricted normal seller/manager/viewer | Existing legitimate operations and role recipes continue; no extra grant or ownership-derived rights. |

Extend `backend/tests/Feature/Modules/Sales/CrmFoundationTest.php` and `backend/tests/Feature/Modules/Parties/PartyIdentityReuseTest.php`; include mixed-grant cases missing from current tests. Run applicable permission/read/write/
scope/controller ratchets and backend checks under pre-push standards. Test denial bodies and
unchanged database facts, not status alone. Implementation owns all tests and execution.

## API, UI, scope and completion

Keep routes, response shapes, existing capability names and normal 403 unsupported-mode denials.
Keep existing cross-company/out-of-scope conventions; do not blanket-convert 403 to 404. A
permission-only change needs no generated response redesign; regenerate only if API-described
contracts actually change. Inspect `frontend/packages/core-ui/src/features/sales/sales-common.tsx` (including candidate
selection) and its existing lead/opportunity screens: current helpers use capability visibility,
not proof of record scope. Handle server denial without stale candidate/count display; reflect
effective scope only through an established server contract, never a guessed client unit filter.
Server refusal stays authoritative after revocation. Existing CRM browser scenarios must cover
mixed-grant denial and legitimate Party search; no new CRM screens or global retrofit.

No new duty or permission migration is expected. If implementation finds one necessary, document
registration, existing legitimate grants and rollout before delivery. Exclude business decisions
A/B/C, forecast/acceptance, cost C2, Sales unit visibility and shared resolver redesign. Handoff
ready means the specification is ready, not that the application correction is delivered.

## Related

- [CRM policy review and future gates](phase-4-crm-policy-review.md) · [A/B/C specification](phase-4-crm-correction-specification.md)
- [Authorisation](../architecture/authorization.md) · [Pre-push checks](../architecture/pre-push-checks.md)
- [Sales module](../../backend/app/Modules/Sales/README.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
