[← Phase index](README.md)

# R4 — Financial implementation slices

**2026-10-12 · ACCEPTED architecture / SPECIFIED slices / implementation NOT AUTHORISED.**
ADRs [0118](../decisions/0118-pin-quotation-pricing-dates-and-company-base-fx.md),
[0119](../decisions/0119-determine-versioned-quotation-tax-components.md),
[0120](../decisions/0120-settle-quotation-rounding-and-financial-evidence.md) close Q44.
The [typed contract](../domain/quotation-financial-calculation.md) defines models, Actions/Requests,
pipeline, database obligations and proposed public APIs. Slice completion means authorised coding
and its own verification, not this document. Handoff to the designated coding agent goes through
owner. CRM/C2 delivery and generated files stay untouched; C1d remains separately gated.

## Duties and rollout gate

The following are **proposed duty contracts for R9**, not registered/accepted permission names.
Reuse genuinely suitable existing vocabulary before registration; new sensitive duties require
owner review. Finalise and ship exact Request+Action guards, scoped reads/fields, registry entries,
explicit grant migration and tests **before enabling any associated public surface**.

| Duty | Narrow contract / candidate vocabulary | Grant boundary |
|---|---|---|
| Manual rate maintenance | Existing `currency.exchange-rate.manage`; existing guarded master API | Reuse existing grant conventions; not authority to override chosen quote FX. |
| Sales FX and financial rounding configuration | Candidate `sales.financial.configure`; version/activate effective policy, not quote editing | Explicit designated company configuration administrators; no silent pricing-editor grant. |
| Tax component/group/rate configuration | Candidate `tax.configuration.manage`; same-company governed economic versions | Designated tax administrators; pricing mode duty alone insufficient. |
| Party tax profile/evidence maintenance | Candidate `tax.profile.manage`; independent Party read eligibility and effective record scope | Explicit profile administrators; membership/pricing-group manager insufficient. |
| Exemption override / statutory exception | Separate sensitive duty if later approved; no initial generic bypass | Owner/R9 must settle exact supported exception and evidence; routine profile evidence is not exemption invention. |
| Financial preview/quote operation | R3 proposed quote preparation and protected-term duties, independent quote/Party/source read | R5/R9 effective limited-record scope; amount view never grants cost view. |
| Risk/discount/contract departure | Existing R3/R5/R9 unresolved sensitive duty contracts | No general bypass or ordinary-editor automatic grant. |

Full-access permission re-sync only for a role whose existing definition explicitly grants all
registered capabilities, proven by contract rather than role name. Registration is not assignment;
new duties do not silently land on managers/salespeople. Configuration-only authority does not
permit arbitrary records, exemptions, FX selection override, approvals or cost disclosure.

## FR0 — Arithmetic and evidence primitives

**Depends:** 0012, 0110, 0118–0120, R3 PR0 interfaces and separate coding authorisation.
**Model/Actions:** storage4 monetary adapter, higher-precision decimal/rational calculations,
quantity representability, `FinancialResult`, status/null invariants, raw/settled/computed/allocated
value types. Internal calculation helpers only; no public route or quote schema yet.
**Required investigation:** reproduce MoneyCast load/store of0.3350 in a two-place currency,
identify caller impact, choose scoped Sales custom4/rational adapter, record evidence. A shared-cast
fix affecting other modules requires separately authorised impact review; never silently broaden FR0.
**API/UI:** none; architecture does not require a fake operational screen for a kernel.
**Permissions/demo:** no new grant/internal duty; exact caller guards still required. Decimal fixtures
are calculation examples, not edits to implementation agent's module seeders.
**Acceptance:** storage4 round-trip and intermediate rational conservation; currency quantum0/2/4
supported or explicit unsupported refusal; overflow/non-finite/negative input refusal; quantity
fraction unsupported at ledger precision refuses instead of changing scope; PARTIAL cost never
becomes complete; no regression in existing monetary callers if any shared change is authorised.
**Migration:** no need to invent a table for value objects. Any real new constraints follow database
rollback contract. Coding checks below; investigation remains unverified until coding evidence.

## FR1 — Dates, FX policy and immutable bridge evidence

**Depends:** FR0, company0081/manual0031; public duties approved/delivered under R9.
**Model/Actions/Requests:** `sales_fx_policy_versions`, copied FX leg/bundle evidence;
`ResolveQuotationFx`, store/activate FX policy Actions + matching guarded Requests. Generation lock,
effective date validation and expected version; configuration writes idempotent. Rate master remains
existing implementation; no automatic providers/new currency hierarchy.
**API/UI:** proposed `/sales-fx-policies` version/activate, configuration sheet with exact-date default,
bounded prior max age and through-date presentation. Read-only scoped admin preview shows each leg,
actual/requested dates and age; operational quote currency/date/refresh waits FR5/R5.
**Permissions/demo:** narrow financial configuration duty; master edits retain existing duty.
Future isolated demo has base identity, USD/source and EUR/document bridge, exact/prior/missing/overage
cases with explicit policy; not actual jurisdiction config.
**Acceptance:** adjacent effective periods never double-match; local midnight differs from UTC date;
identity only same currency; USD100/280/350⇒EUR80/base28000; each actual date retained; day-age
boundary inclusive max age, future/non-positive/missing/too-old unsupported legs refuse; direct rate
cannot replace bridge; mutable master edits/deletes cannot change captured evidence. Company/scope,
Request and direct Action denials; same-key replay/payload conflict/concurrent version activation.
**Migration:** additive version rows/indexed company/date lookup, valid interval CHECKs and exact
partition exclusions. Snapshot company/candidate/issue FKs bind specified R5 keys; no inferred historical rate backfill.

## FR2 — Classification, Party profiles and tax groups

**Depends:** FR0/FR1 effective dates; 0056/0119; R9 configuration/profile duties for public setup.
**Model/Actions/Requests:** source classification and Party profile/exemption versions; tax component
rate/rule/group/dependency versions. `DetermineQuotationTax`, create successor/activate group/profile
Actions and Requests; lock generation and validate same-company graph before activation.
**API/UI:** proposed `/tax-group-versions`, `/parties/{party}/tax-profile-versions`; setup shows stable
component names, methods/bases, named dependencies, RAW/SETTLED stage, dates and mode requirements.
Typed source nature defaults visible; no CustomerAccount creation or free-text tax-code substitute.
**Permissions/demo:** independent profile/Party scope; pricing groups confer no statutory authority.
Future demo: goods10%+5%, service5%, one component-exempt Party, unknown profile, fixed/per-unit
configurations clearly marked unsupported until FR3/FR6 engine support.
**Acceptance:** service Item not goods; Assembly/manual classification correct; prospect eligible;
component exemption distinct from zero/not-applicable/unknown; cross-company graph/profile denial;
cycles/missing predecessors/duplicate component IDs/incompatible currencies or bases refuse activation;
effective rates and profile changes retain old versions. Concurrent changes cannot activate an
unvalidated graph. Protected Party exemption evidence absent from unauthorised nested/errors/history.
**Migration:** composite FKs, immutable-used versions, method field CHECKs and interval uniqueness;
truthful captured-after-receipt baselines for preexisting rows, no manufactured certificates/tax history.

## FR3 — Tax composition, inclusive normalisation and withholding preview

**Depends:** FR0–FR2; FR4 rounding primitives integrated before trusted totals; R3 price adapter.
**Model/Actions/Requests:** `NormaliseInclusivePrice`, `CalculateQuotationFinancials`, separate
withholding estimate result; explicit input intent/included set, typed methods/dependency evidence.
Read-only preview Request+Action uses server-selected facts; no adopted quote/Finance side effects.
**Initial execution proposal:** BASIC supported single percentage NET component per line with explicit
dispositions and separate supported withholding estimates. Multiple-component LOCALISED and other
LOCALISED/ADVANCED methods can be delivered later via
FR6; each advertised method requires complete acceptance coverage. It is acceptable to deliver a
larger tested subset under separate coding approval; never claim unsupported execution from schema.
**API/UI:** scoped configuration preview; exact net/output/gross plus separate withholding/payment,
component breakdown and known/unknown state. Unsupported inclusion/template clearly refuses.
Operational preview endpoint waits R5/FR5; no public quote identity fabricated.
**Permissions/demo:** respective config preview duty and legitimate Party/source access; no cost leak.
Future fixture pack reuses [numerical examples](phase-4-r4-tax-composition.md), not country tax law.
**Acceptance:** for supported methods net1000 additive⇒1150, compound⇒1155, tax-only⇒1105;
fixedline10⇒1010; perunit2×20⇒1040; exempt predecessor knownzero versus unknown refusal;
mixed Item rates within one document; included1150 additive/1155 compound normalises1000;
NET_FIXED versus GROSS_FIXED exemption impact preserved; one benefit never applied twice.
Unsupported methods/inversion refuse stable code; separate withholding5%net=50 or5%gross=55,
missing cumulative facts payment UNKNOWN without altering net/gross/margin. Tenant/direct Action
and sensitive Resources tested, not just HTTP button hiding. Cases only claimed passed for enabled methods.

## FR4 — Settlement, deterministic allocation and final-net controls

**Depends:** FR0–FR3, R3 PR4 controls/0116, complete applicable cost0110, 0120 policies.
**Model/Actions/Requests:** rounding policy versions, raw/settled component evidence, allocation
weights/ties, computed-base versus allocated contribution/residual; `AllocateFinancialResiduals`,
`EvaluateSettledNetControls`; guarded rounding-policy version/activate Requests and Actions.
**API/UI:** proposed `/quotation-rounding-policies`; config explains unit/line/tax aggregation stages,
eligible grouping and inclusion compatibility. Control results PASS/WARN/FAIL/UNEVALUABLE; server-only
cost comparisons project safely. Preview cannot grant exception authority or persist an approval.
**Permissions/demo:** financial configuration duty; exact risk duty remains R5/R9; no new bypass.
Future demo distinguishes additive/compound, line/aggregate tax, rounding residual and cost UNKNOWN.
**Acceptance:** qty3×0.3350 at unit4 settles1.01; three such lines sum3.03, never raw-header3.02;
three0.05/10% line tax0.03 versus eligible aggregate0.02 allocated0.01/0.01/0;
1 benefit across equal100 bases allocates0.34/0.33/0.33; tie stable despite presentation reorder;
computed bases0.0099 versus header0.0100 and distinct0.0001 allocation residual retained.
No amount allocated to exempt/ineligible/unknown lines; sum/caps/non-negative constraints hold.
RAW/SETTLED dependent tax stages reproduce their distinct results; incompatible profiles refuse.
Final controls use settled net and complete comparable quantity/base cost; missing FX/cost mandatory
UNEVALUABLE; advisory warning; output tax/withholding do not inflate margin; zero legitimate net has
undefined percent margin, not an invented ratio. Inclusive extraction rechecks floors after rounding.
**Migration:** versioned policy/evidence constraints; R5 candidate/issue snapshot keys specified; coding remains separately authorised. No GL journals.

## FR5 — Operational quotation financial evidence and UI

**Depends:** FR0–FR4 supported subset + R3 PR5; [accepted R5 identities/state/adoption/issue/concurrency](phase-4-r5-implementation-slices.md),
R9 duty/grants; R6 chosen-scope integration before options/partial operations. No implementation gate
is satisfied merely by R4 acceptance. R8 documents/delivery separate.
**Model/Actions/Requests:** R5-owned financial snapshots/components/allocations/receipt references;
R5 prepare/reprice/adopt/issue Requests/Actions integrate current pipeline in one transaction and
stable aggregate locks. Freeze immutable issued evidence; operational preview itself does not adopt.
**API/UI:** R5 `/quotations/{id}/preview` composed financial result and financial result Resource; form/detail/history
show price/tax dates, one currency, FX age/route, intent/included taxes, net/output/gross and withholding/
unknown payment. Explicit refresh confirmation and stale states; customer-safe explanation distinct
from internal cost/authority trace. All standard empty/loading/error/denied/mobile/dark/light states.
**Permissions/demo:** R5/R9 scoped quote duties plus independent Party/source eligibility; known
config capabilities insufficient. Designated future Sales demo, ordinary seller/limited/administrator
personas; excluded unsupported methods and real grants not manufactured by demo.
**Acceptance:** changed date/rate/profile/classification/component/cost/mode marks relevant draft
stale; issue revalidates now-effective facts without silently repricing; concurrent edit/reprice/
issue refuses stale snapshot; retries duplicate neither snapshot nor number; current authorisation
checked before replay. Issued evidence unchanged after master edits or downgrade; privileged
financial preview not adopted by unprivileged caller; cross-company nested/history leaks prevented.
Exact approval fingerprint, issue numbering and UNKNOWN issue policy are now specified by R5;
verify QL0–QL6 adapters and R9 scope/grants before enabling, not presumed passed by financial tests.
**Migration:** bind specified R5 composite aggregate/line keys; append-only evidence, allocation
links to original/remaining scope reserved for R6. Real rollback/reapply by coding owner.

## FR6 — Additional supported tax execution and transitions

**Depends:** earlier slices; each specific tax method's mode/support matrix, accepted localisation
configuration and R5/R9 transition authority. Finance-owned legal adapters require later approval.
**Model/Actions/Requests:** shared graph contract supports fixed-line/document, per-unit package/stock
basis, compound/tax-only, mixed inclusion and aggregate dependent rounding. Add method adapters,
activation support validation, explicit mode-transition Requests/Actions without alternate tax schema.
**API/UI:** relevant existing setup/preview/quote surfaces expose supported advanced controls and
compatibility refusal. Downgrade reviews active groups/drafts/unknown effects; history stays readable.
**Permissions/demo:** approved exact transition/config duties; no routine tax exemption/risk override.
Future demo exercises one supported combination per enabled method with trace and failure examples.
**Acceptance:** document fixed component executes once; allocation conserved; offered cartons10×10
stockunits with levy2 perstockunit yields200 versus levy2 percarton yields20; wrong declared basis
refuses. Sequence/cycle/raw-settled/rounding and NET_FIXED/GROSS_FIXED cases all verified per enabled
adapter; unsupported mode fails closed; downgrade cannot conceal active effects or rewrite history.
No invoice legal tax, reverse-charge ledger, return/certificate/payment engine claimed delivered.

## Common delivery checks and dependency summary

Every authorised slice: Actions transaction/idempotency/stable locks, scoped reads and sensitive
field Resources; explicit permissions/grants; DB constraints; OpenAPI/client generation together;
required ratchets and cross-company/direct Action/concurrency tests; genuine demo journeys and
API/UI coverage matrix. Follow [pre-push checks](../architecture/pre-push-checks.md) for actual coding
changes including migrations/apply→rollback→reapply. This planning commit runs docs checks only.
Do not hand-edit generated files or coding agent's shared delivery documentation.

R4 resolves architectural dates/FX/tax/precision for R3 PR0–PR6. FR0–FR4 can be specified and,
once separately authorised with R9 public duties, delivered as foundations/configuration previews.
Operational FR5 remains gated by R5/R9; R6 partial allocations, R7 handover, R8 export/delivery,
R9 forecast trust/partial-win/remaining reconciliation stay independent. Allocation links are designed
now, not partial acceptance or Finance credit implementation. All R4 slices are NOT_STARTED for coding;
none is IMPLEMENTED or VERIFIED by this specification. No coding-originated question was forwarded.

## Related

- [Financial contract](../domain/quotation-financial-calculation.md) · [R4 review](phase-4-r4-financial-review.md)
- [R4 evidence/readiness](phase-4-r4-evidence-and-readiness.md) · [R3 slices](phase-4-r3-implementation-slices.md)
- [R5 initial research](phase-4-r5-quotation-lifecycle.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Question reconciliation](../architecture/implementation-question-reconciliation.md)
