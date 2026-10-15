[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R3 — Pricing implementation handoff and integration gates

**2026-10-12 · BUSINESS ARCHITECTURE ACCEPTED / SLICES SPECIFIED / NO CODE AUTHORISATION.**
P1–P8 are governed by accepted [0115](../decisions/0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md),
[0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md) and
[0117](../decisions/0117-preserve-pricing-evidence-across-capability-transitions.md).
The owner coordinates the designated implementation agent. This handoff is a proposed delivery
sequence with complete boundaries; enabling a slice still needs explicit owner code authorisation
and its listed integration authority. CRM/C2 source, migrations, tests and delivery docs are not ours.

## Contract and ownership

Use [pricing domain](../domain/sales-pricing.md) and
[typed resolution/Actions/schema/API specification](../domain/sales-pricing-resolution.md) as the
implementation contracts. One resolver, four adapters: direct Item, service Item, Assembly work,
governed manual. Common model supports audiences/categories/terms/benefit/rate allowance/context,
independent measures, controls and versioned evidence now; optional screens/execution follow approved
slices. No tables that pretend a pricing draft is a quotation or a quote is acceptance/stock posting.

No new owner business choice prevents drafting these R3 specifications: P1–P8/Q43 are settled.
Permission vocabulary/limits and R4–R9 transitions remain known integration gates, not invented
implementation questions. When forwarded, use [accepted reconciliation](../architecture/implementation-question-reconciliation.md),
with **Origin: IMPLEMENTATION AGENT**, exact question/path/commit, example, checked authority,
true blocker and coding verification evidence. Clarification is never verification. Independent work continues.

## Effective duty contract — proposed names, not approved grants

Reviewed Sales vocabulary has pipeline/Lead/Opportunity/stage/numbering/attribution duties, not pricing
configuration or quotation approval. Reuse source/Party reads and Inventory package maintenance only
for those exact duties; do not repurpose Opportunity manage, Parties financial management or protected
cost-read as pricing authority. R9 reconciles final names/limits/grants before a surface is enabled.

| Duty / proposed registry name | Required eligibility and minimum projection | Grant / scope gate |
|---|---|---|
| Configure base prices/policies/plans/controls/modes · `sales.pricing.configure` | Company config duty; only supported config scope; minimum read of its writable terms, no cost/stock operands by implication. | Explicit designated pricing administrator role recipe; operating a quote grants none. R9 accepts initial explicit company-wide config scope; unsupported limited config refuses 403. |
| Maintain Party pricing eligibility · `sales.pricing.eligibility.manage` | Duty AND independently legitimate Party read/record eligibility. Group list/config obeys config scope. | Designated eligibility maintainers; no membership from commercial labels, no CustomerAccount prerequisite. |
| Maintain contractual pricing · `sales.pricing.agreement.manage` | Duty AND legitimate contracting Party/term scope; preserve approved fixed terms/version history. | Separate agreement-maintainer assessment; config manager/ordinary seller is not automatically contract administrator. |
| Read competing/private price terms · `sales.pricing.terms.view` | Company/effective Party/agreement/price-term scope; sensitive fields on every surface. | Explicit confidential commercial read; chosen authorised quote net does not require all competitor/customer terms. |
| Prepare quote/pricing · `sales.quotation.prepare` | R5 editable document + independently eligible quote/Party/source scope; safe chosen-commercial-price projection. | R9 quotation role/scope contract; CRM edit does not imply prepare. Admin hypothetical preview uses config duty, not a backdoor quote write. |
| Enter bounded routine discount · `sales.quotation.discount` | Prepare AND effective amount/percent/aggregate limits, plan/term allowance and confirmed replacement when applicable. | Explicit selling duty/limit recipe; does not grant override/approval/risk. Unknown required limit basis refuses. |
| Enter final-price exception · `sales.quotation.price.override` | Prepare AND reason, effective scope/limits, terms and all final controls. | Separately assigned exception duty; ordinary discount entry cannot call it indirectly. |
| Approve above-limit discount / risk | R5 approved subject + legitimate read, stored maker/checker, exact facts and R9 narrow authority. | Names and approval semantics deliberately gated. No initial risk-bypass endpoint or ordinary manager grant. |
| Read cost/margin/stock/recipe operands | Existing relevant Inventory/Estimating confidential duties AND applicable scope. | Reuse exact field authority; pricing/control evaluation may be blind under 0110, never broadens operand read. |

Every endpoint/Action takes explicit actor/current company; exact duty scope controls writes, unrelated
Sales grants cannot widen it. Independently legitimate read alternatives may authorise source access,
not elevate a narrow duty. Out-of-scope ID 404; unsupported limited surface 403; lists/count/search/
lookups/includes/filters/sorts/exports are narrowed or refused and audited. Source identity being shared
reference does not make Party prices/agreements public. Quotes require their future R9 scope contract.

At coding rollout: register final vocabulary unfiltered; explicit company role/grant mapping before
exposure, no automatic ordinary salesperson/editor/assigner/closer/manager grants. Preserve legitimate
same-duty continuity only where evidenced; pricing is new duty, not CRM continuity. Administrator
expansion follows machine-verifiable registered full-access mandates and sensitive exclusions; a role
name/current all-permission snapshot never proves eligibility. Review existing grant helpers, seeders,
role recipes and ratchets without expanding 0114's attribution-specific policy silently.

## Slice sequence

All slices carry the common transaction/constraint/migration/verification contract below. Backend
foundation may precede UI only when explicitly unexposed; an enabled operational slice includes its
stated Resources, generated client, navigation, states, forms/details/history and persona coverage.

### PR0 — Shared typed model and resolution kernel

**Depends:** 0115–0117, 0109/0110 and explicit implementation authorisation. Accepted R4 (0118–0120) resolves persistence
precision/date/dual-money before relevant migrations; R9 before any public surface.
**Deliver:** typed input/result and source adapters; eligibility/ranking/exclusion/benefit evaluation,
complete/partial/unknown semantics, source/quantity basis and projection interfaces. Internal Action
only, no public entry point, no enabled advanced mode, no new quotation tables or UI claiming delivery.
Keep source-neutral shape ready for later terms even when management UI is deferred.
**Permission/security:** explicit internal actor, current company and independently authorised source/
Party context; staged Action cannot become authenticated-only when a route is added.
**Acceptance/regression:** direct cartons/service hours/Assembly work/manual line without fake masters;
Party without account; equal-rank conflict; no cheapest selection; no unknown-cost zero; no stock post.
**Demo:** kernel scenarios as future test fixtures; no seeder writing an inaccessible fake quotation.
**Exclude:** quote save/issue/adoption, forecast, FX/tax, risk bypass, all public pricing screens.

### PR1 — Maintained configuration and SIMPLE pricing inputs

**Depends:** PR0; accepted R4 monetary/date/precision adapter (FR0/FR1/FR3/FR4 supported subset); R9 config/entry/discount/override duties.
**Deliver API:** list/detail/create-draft-version/activate/retire base rules/list and configuration preview
from resolution spec, registered exact Actions/Requests/Resources. Base identity or explicitly allowed
pinned base-bridge conversion follows accepted R4 and delivered adapters. SIMPLE percent/per-priced-unit/
per-line intent, comparison result and controlled final
exception are typed resolver inputs, not yet a public quotation mutation.
**UI boundary:** pricing settings list/detail and sheets for maintained rates/company/source policies;
admin preview supports all four sources, quantities/basis, calculated/final and safe diagnostics.
Simple operational line form is delivered in PR5 after R5, not claimed here. Unsupported managed
fields/modes hidden/refused; no arbitrary prices inferred from cost guidance.
**Permission/security:** configure for setup/preview, minimum writable-term read only; no confidential
competitor terms or cost by implication; direct Action denial and company/scope cases.
**Acceptance/regression:** valid zero rate explicit versus missing price refusal, complete-cost markup
120 versus target margin125, maintained price independent of cost, manual base/no fabricated master;
100 per-unit versus line discount; 20%-to-10% comparison shows increase and needs confirmation.
**Demo:** designated pricing maintainer, quote-only persona denied config, cost-blind preview; all sources.
**Exclude:** managed group lookup, unsupported currency routes, issue/approval, unconfirmed replacement save.

### PR2 — Groups, categories, individual terms and exclusions

**Depends:** PR1, R4 effective membership/rule interval, R9 membership/term confidentiality/config scope.
**Deliver API/UI:** group and dated Party membership list/detail/history/sheets, exact/subtree category
and Item rule editor, Party/group/general maintained terms, named family exclusions and preview trace.
Party detail may expose membership only in this explicit complete slice; does not edit Party kind or
financial account. Use existing category picker/path, not a parallel hierarchy. Config filters match
validated audience/source/category/status fields, sensitive price sorting gated.
**Permission/security:** eligibility duty + legitimate Party scope, protected terms/read; prospect works.
**Acceptance/regression:** distributor+partner priority (20 beats lower-priority30), Party/category beats
group/Item, Party/Item35 replaces Party/category25, child/ancestor rules, same-family deny overrides
positive, new Item/move invalidates relevant draft dependencies but not issue history. Conflicting
activation races cannot both become valid; equivalent candidate identity ordering remains stable.
**Demo:** multiple groups, accountless prospect, inactive membership, premium exclusion, multiple category
levels and enough records for paginated selectors; no real Party enrolment backfill.
**Exclude:** classification management UI/automatic mappings, tier aggregation, commitments or contract departure.

### PR3 — Line-local tiers, selected agreements and explicit plans

**Depends:** PR2; R4 eligibility/basis precision; R9 contract duty; R5/R6 integration before operational
selected agreements/options appear in quotes. Ordinary rules can be previewed without pretending adoption.
**Deliver API/UI:** tier-band/rate allowance sheets, agreement pricing versions/coverage/extra-discount
allowance, named ordered adjustment plans and exclusions, context eligibility if explicitly enabled;
maintainer preview shows base/benefit/order/terms and rejects same benefit in rate plus slot.
Agreement entry models pricing terms only, no legal contract approval/commitment consumption ledger.
**Permission/security:** config/contract/Party read evaluated separately; candidate agreement list scoped,
no unrestricted customer-term discovery or price-based search without duty.
**Acceptance/regression:** 10 cartons at900 all-units, 100 loose excluded, two6 lines not pooled;
900 permits20%=720 versus exclusive900; selected850 beats ordinary650, invalid selected agreement
refuses fallback; uncovered line only if terms permit; 10%-then5%=855, no same campaign twice.
**Demo:** fixed/reference-discount agreements, explicit plan allow/deny, tier/rate benefit provenance,
expired selected terms, unsupported alternative aggregation refusal.
**Exclude:** risk/contract departure, marginal/pooled/cumulative tiers, free goods, rebate/loyalty settlement.

### PR4 — Final-net controls and effective commercial authority

**Depends:** PR0–PR3 as applicable; R4 comparable final net/rounding; R9 effective duty/limit/reference
contract. R5 before quote approval/issue; no initial risk-exception execution.
**Deliver API/UI:** versioned floor/minimum-margin/advisory/mandatory control configuration, guarded
internal evaluator and safe outcome preview; effective routine/final override checks incl aggregate
reference. Control settings explain missing prerequisites; operational warnings/refusals surface in PR5.
**Permission/security:** separate configure/enter/override/read; bound authority to actual actor/company/
scope, not submitted manager role. Policy references and values are projected only where authorised.
**Acceptance/regression:** final650 cost400 passes, final585 fails floor600; margin/floor independence;
mandatory missing cost blocks, advisory unknown warns, genuine zero evidence works, zero-net margin
undefined; no subtotal denominator or price-edit risk bypass. Confirmed replacement still runs controls.
**Demo:** cost-blind seller, cost reader, config maintainer, below-limit/above-limit, unknown cost and
separate warning/refusal. No fake approved risk exception persona or broad manager bypass.
**Exclude:** risk decision endpoint/grant, agreement deviation, R5 approval schema, accounting profitability.

### PR5 — SIMPLE operational quote pricing and evidence integration

**Depends:** PR0/PR1/PR4; enabled PR2/PR3 only for approved MANAGED exposure; accepted R4, R5 and
R9 operational contracts. R6/R7 inform R5 and option/partial integration before those paths deliver.
**Deliver API/UI:** pricing inputs/preview/draft changes/history embedded in one R5 quotation/revision/
line API; complete Resource/OpenAPI/client and shared composer/detail/read-only/history screens.
SIMPLE main fields base/rate/basis, one routine discount, safe calculated/final/warnings; explicit
replacement sheet shows both results/delta and records exact-facts confirmation. Final exception
separate reasoned review. Selected agreement/eligible managed controls appear only when delivered/
enabled. Relevant stale before/after review, server-derived actions and readonly issued evidence.
**Permission/security:** prepare AND exact entry/override duty/limits; R5 state + R9 record/field scope;
no HTTP-only checks or protected operands in errors/history/customer payload. Every save/confirm is
idempotent under document transaction/lock order, no evidence write on GET.
**Acceptance/regression:** cancellation retains automatic20%; manual10% replacement requires positive
confirmation before net increases; stale/different comparison invalidates confirmation; retry no new
facts, changed intent refuses; category/membership/config/cost changes revalidate, valid no-change
requires current support; issued snapshots unchanged, no auto forecast/win/job/reservation.
**Demo:** four source lines, prospect, viewer, restricted/no-cost seller, permitted/denied discount/
override; SIMPLE default and MANAGED scenario only if its dependency slice delivered. Phone/light/dark,
keyboard/focus, stale/refusal/loading/error/empty and saved advanced value reopen coverage.
**Exclude:** R6 partial/options acceptance not approved; R7 creation, R8 PDF/portal/delivery, pricing-owned
issue endpoint, risk bypass or forecasts from draft net. R5 owns issue endpoint itself.

### PR6 — ADVANCED formulas and safe mode transitions

**Depends:** PR5, R5 downgrade/reconciliation and R9 mode/formula duties; R4 typed unit/currency inputs.
**Deliver API/UI:** immutable typed formula versions/steps/input resolution/preview cases and full enabled
operational exposure; approved bounded conditions/ordered plans, writer matrix/database mode guards.
Activation and downgrade impact/reconciliation sheets with history; no unrestricted expression editor.
**Permission/security:** mode/config duty, typed resource limits and operands protected; same guards on
jobs/imports/non-HTTP writers. Dependencies outside supported R5 lifecycle make downgrade refuse.
**Acceptance/regression:** incompatible unit/missing input/division zero fail; activation incomplete fails;
advanced draft/pending approval prevents hidden downgrade; issued formula explanation remains historical;
never-enabled companies retain SIMPLE behaviour; no mode change cancels agreements or disables control.
**Demo:** valid governed formula, invalid preview, dependency-blocked downgrade, resolved transition under
R5 and archived issued explanation. Do not seed already-accepted advanced execution before delivery.
**Exclude:** arbitrary SQL/scripts, unapproved geographic hierarchy, promotion/rebate/fulfilment engines.

## Common delivery completion contract — every applicable slice

Actions own writes in one transaction; exact actor/company/request key and canonical intent receipts,
stable locks/re-read/version checks, current-access replay and `STALE_EVIDENCE` refusals. Append-only
used facts and economic version immutability; database composite FKs, monetary/quantity/type/interval/
benefit constraints and writer coverage. No fabricated generic-audit history, silent rounding or resets.
Use resolution-spec API reasons/Resource projections and standard envelopes; guard Request and Action.
Migration additive, default SIMPLE/no auto enrolment, truthful idempotent company backfill, FinancialActivity
probe for new monetary rows, real down/refusal with evidence; isolated apply/rollback/reapply by coding.

Complete generated schema/client, registered scoped routes/Actions, domain/journey/demos and safe grant
rollout in coding commit. Configuration-first slices ship their complete configuration screens, not
fake quote screens. PR5/PR6 operational features cannot be enabled with missing form/detail/history
controls. All response facts/validated filters have UI destination or documented deferred/backend-only
reason; sheets/shared shell/tokens/hooks, phone no overflow, keyboard/focus, light/dark and every state.

Required coding checks follow [pre-push matrix](../architecture/pre-push-checks.md): appropriate Action/
API/tenant/field/idempotency/concurrency/raw-SQL tests; route/scope/permission/controller ratchets;
Pint/Larastan for PHP; fresh/rollback/reapply for migrations; regenerated client/drift and all frontend
typechecks/build; relevant repeated browser journey/axe; idempotent demo fresh/rerun. Protect 0110
cost propagation/blind evaluation and existing Item/Party/Estimating/CRM behaviour. Record actual
passed checks or verified cause/owner; planning has run documentation checks only, no suites here.

## Exact remaining integration gates — not new R3 blockers

| Owner topic | Required accepted contract before affected implementation | Affected slices / safe boundary |
|---|---|---|
| **R4 — ACCEPTED0118–0120** | Explicit dates/local half-open intervals; unit4/storage4 and representable quantity; pinned base-bridge manual FX/exact-date or bounded prior; per-line components/dispositions and net/gross normalisation; settled-line/document and tax rounding, conserved allocations/computed-vs-allocated base evidence; comparable final net controls. | Architectural dependencies resolved for PR0–PR6; implement supported adapters under separate authority. Required scoped MoneyCast investigation and enabled-method verification remain; R5 keys are now specified; actual QL adapters and R9 grants still gate enabled operational schema/surfaces. |
| **R5 — ACCEPTED0122–0124** | Family/revision/logical-scope identities; source candidate/adoption/freshness; both-hash no-change review; shared coordinator/atomic issue/number/receipt; explicit expiry, whole-unaccepted withdrawal/replacement; BLOCK/acknowledged UNKNOWN payment. [Lifecycle contract](../domain/quotation-lifecycle.md) and [QL0–QL6](phase-4-r5-implementation-slices.md) bind these seams. | Semantics/typed keys specified, not delivered. PR5/FR5 require actual QL adapters and R9 scope/grants; PR6 mode reconciliation. Partial replacement blocked until R6/R7 approved and delivered; risk/contract exceptions not enabled. |
| **R6** | Baseline/selected alternatives/add-ons, whole/partial acceptance authority/evidence; whether changed accepted quantity loses a tier; fixed-line/ordered-benefit allocation and consent to changed commercial amount; family/remaining-scope reconciliation. | No pooled tier/partial acceptance/option-dependent operational pricing before gate; PR5 evidence seam only. Issued offer never edited to simulate acceptance. |
| **R7** | Idempotent accepted scope→order/job/baseline with accepted estimate/contact/site; commitment fulfilment/cancellation links and actual cost/correction ownership. | No consumption/fulfilment/handover/posting from PR3 terms or PR5 prices; preserve identifiers/provenance only. |
| **R8** | Customer commercial/discount/terms projection, immutable templates/PDF, delivery/re-delivery/channel evidence; signature/portal/manual-recording boundaries. | PR5 safe commercial API/UI projection only; no pricing print/export raw trace. Separate R8 complete surface review. |
| **R9** | Final permission vocabulary, company duty/read/sensitive-field scopes, employee/role limits and trusted reference; grant continuity/explicit new-duty rollout; approval/risk/contract scope; audit/import/event projections; selected-offer provisional expected-value/FX/staleness and partial-win/remaining metrics. | Every public surface and PR4 authority; no all-role assumed grants, draft-price trusted forecast or accepted-scope double count. Existing 0101/0102 and R5/R6 gates remain required. |

[Accepted R4/readiness](phase-4-r4-evidence-and-readiness.md) resolves the financial architecture,
with [R4 typed contract](../domain/quotation-financial-calculation.md) and
[slices](phase-4-r4-implementation-slices.md) specified. This is no code authorisation or verified
adapter delivery. [Accepted R5](phase-4-r5-implementation-slices.md) now binds lifecycle seams;
[R6](phase-4-r6-customer-decisions.md)/R7 partial/consent/claim/commitment gates remain before those operations. R9 proceeds alongside every public slice. C1d stays separately gated;
no implementation-originated question was supplied or invented.

## R9 closure and coordinated delivery —2026-10-15

R9 A1–A10 accepted0138–0140/Q49 closed. [Canonical duty matrix](../domain/sales-duty-matrix.md),
[safe rollout](phase-4-r9-permission-rollout.md) and [R9 slices](phase-4-r9-implementation-slices.md)
resolve previously proposed vocabulary/grant architecture; historical candidate names above yield
to this reconciled catalogue, not duplicate guards. Actual guards/holder inventory/adapters/verification
remain required before affected public routes. [Consolidated handoff](phase-4-implementation-handoff.md)
defines small dependency batches; no implementation/live grant authorisation. Partial-family and
downstream gates stay until exact affected contracts actually delivered.

## Related

- [Pricing domain](../domain/sales-pricing.md) · [Resolution/model specification](../domain/sales-pricing-resolution.md)
- [Accepted review](phase-4-r3-business-review.md) · [Worked example](phase-4-r3-worked-example.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Phase index](README.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md) · [Screen delivery](../architecture/screen-delivery-contract.md)
