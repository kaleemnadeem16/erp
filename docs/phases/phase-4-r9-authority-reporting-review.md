[← Phase index](README.md)

# R9 — Sales authority, reporting and forecast integration

**2026-10-15 · FINAL CONSOLIDATED OWNER REVIEW / PROPOSED / planning Q49 READY_FOR_OWNER.**
R1–R8 authority is reused, not reopened. No R9 ADR accepted; names, rollout and reporting refinements
below require owner review before accepted R9 ADRs/specifications. Framework0137 is already accepted; R9 is not. No application coding or real grants authorised.
Recommended next order: owner reviews A1–A10 and vocabulary, resolves concrete rollout choices, then
accepted ADRs/domain/slices; designated implementation agent later delivers separately authorised scope.

## Accepted constraints and actual inspection

0028/0089 additive company capabilities/record scopes,0084 sensitive Finance fields,0070/0085/0087
approvals/review/reasons,0106 ownership versus authority and0114 sensitive retrospective grants remain.
0107 already fixes OPEN/WON/LOST/ABANDONED outcomes and derived partial-win indication.
0101/0102 fix accepted business versus remaining potential, MAINTAINED/SELECTED_OFFERS, persistent
provisional trust and explicit-dated valuation.0116 preserves independent discount/input/aggregate/
floor/margin/contract checks; no general risk exception initially.0123–0135 distinguish issue,
consent, operations and transmission. Scope/support/authority is not established by a schema.

Read-only source inspection atb3dae82: Sales PermissionVocabulary has eight CRM duties; SalesAccess
permits company-wide per-duty access and refuses unsupported limited scopes with403. mayRead uses
existing CRM duties; attribution correction additionally requires independent legitimate pipeline read.
Do not silently replace this accepted contract with a new mandatory pipeline-view check for incumbents.
Future quotation/decision/target reads need their own approved explicit projection/scope contracts.
PermissionRegistry distinguishes registration from automatic grants and preserves explicitly held
excluded duties in syncAutomatic. FullAccessMandates checks company/role/member/effective approval
reference. It is not proof that future per-duty restricted grant migration has been implemented.
No quotation/forecast/artefact/target delivery is inferred from these existing helpers.

Some architecture pages retain historical sketches (e.g. approval engine missing); accepted ADRs
and implemented domain/Actions take precedence. No global authorisation redesign is proposed here.

## Bounded primary comparison

Question: how to separate routine Sales authority from amount limits and protect forecast reporting
without turning hierarchy or a broad role into unrestricted access? Sources accessed2026-10-15.

| Primary evidence | Meaningful comparison | Translation and limits |
|---|---|---|
| [ERPNext authorisation rules](https://docs.frappe.io/erpnext/authorization-rule) | Amount/discount conditions add authorisation requirements beyond submit permission. | Reuse our capability + exact fact/limit + approval model; do not copy role-name logic or assume an average discount safely controls mixed scope. |
| [Dynamics forecast permissions](https://learn.microsoft.com/en-us/dynamics365/sales/provide-permissions-forecast) | Forecast view/adjust/share can be configured separately with hierarchy or additional roles. | Distinct reporting/read/change duties are useful; our ownership confers no access, and no hierarchy/share override is imported. |

Both separate operation permission from conditional authority/access. Their field/hierarchy models
differ; our per-duty scope and privacy remain governing. Existing R1 primary forecast/FX comparisons,
R3 control research and accepted approvals are sufficient to reuse for settled choices. New detailed
limit/forecast policy refinements below remain proposals, not vendor-mandated or legally required rules.

## Final A1–A10 business-first decision matrix

| Bundle | Options/trade-offs | Practical example | Recommendation for owner review | Dependencies/boundaries |
|---|---|---|---|---|
| **A1 — Duty vocabulary across R1–R8/0137** | One quotation-manage duty (easy, collapses authority); duty vocabulary (precise, more configuration); one permission per table/field (unusable). | Drafter prepares100; authorised issuer issues; acceptance recorder cannot revise price or release Job. | Retain eight existing CRM strings; approve proposed business-duty catalogue below, with ordinary read/minimum workflow projection distinct from sensitive transitions. Reuse existing owning-module duties instead of new Finance/Inventory/Approvals synonyms. | Owner approves exact names/implications, not real users. R3–R8 Request/Action and UI coverage follows actual delivery; no hidden general manage/bypass. |
| **A2 — Record scope and relationship access** | Company-wide first (simple); full restricted Sales scopes (work); inherit owner/Party/target access transitively (unsafe). | Warehouse operator sees eligible delivery destination, not every Party's credit terms or all quotations linked to same customer. | Company-wide only through explicit effective per-duty grant first; unsupported own/assigned/unit modes refuse as0106. Implement restricted paths only with complete list/count/lookup/history/export/mutation coverage. Require each sensitive transition's duty scope AND legitimate source/target projection; unrelated unrestricted duty cannot widen it. | Reuse RecordScope, no second team/tenant resolver. Party candidate selector is narrow workflow access, not directory/Finance grant. No universal live Item/Assembly read solely to see a lawful customer snapshot; owning adapter defines necessary current-source access for mutations. |
| **A3 — Sensitive fields and attachments** | View record→all data (easy, leaks); server projections and independent file access (precise); scrub text automatically (unreliable). | Cost-blind issuer gets required control outcome, not cost/margin threshold or confidential competitor existence. | Reuse existing cost/Finance read gates; separate commercial terms from protected costs/margins and internal evidence. Customer projection is fixed allowlist. R6 attachments/recipient diagnostics and approval reasons use need-to-know profiles/0087 rules, not generic history licence. | Exact Resource/fields/filter/sort/count/print/download/log matrix before public rollout. No scalar zero for redacted cost, no protected operands in errors/hash/comparison candidates. Secure R8 adapter actually delivered. |
| **A4 — Grants and full-access mandates** | Grant every new duty to editors/admin names (quick, escalation); preserve proven incumbents and explicit mandates (safe, needs mapping); revoke broadly on deploy (disruptive). | Existing Sales manager may prepare; cannot acquire retrospective acceptance correction through registry growth. | Map each new capability to exact legitimate previous duty/holder if evidence exists, keeping same company and scope. New sensitive acceptance/correction/late-reconcile/claim-release/exception and authority-policy duties default excluded from automatic growth; explicit authorised assignment or verified full-access mandate only. Existing0114 treatment unchanged. | No current permission-count/name inference. Audit registration, grant migrations, sync/bootstrap/demo/seed/import and scoped role→direct conversions. Mandate must cover actual duty/company/scope; missing proof excludes. Holder decision remains owner's operational step. |
| **A5 — Effective amount/discount authority** | Universal percentage (too coarse); actor limits against current edited base (reset bypass); exact policy reference/input+aggregate controls (sound, more evidence). | Governed reference100.0000; automatic10%, discretionary20% gives80.0000: aggregate20%, not just extra10%. Editing base cannot erase reference. | Pin authoritative reference and discount basis, both specific input and aggregate reduction; apply percentage and absolute ceilings independently, compare amount ceilings in company base using frozen rate/date/source under0070/R4. Missing required reference/FX/cost blocks, no inferred unlimited authority. Versioned authority policy selects one explicit highest-priority applicable rule set; equal-priority conflict refuses. All independent applicable controls must pass; authority grants do not sum ceilings or mix bases. Unknown required basis refuses. | Limits are typed policy/assignments, never permission strings. Company config supplies actual numeric thresholds; recommendation is explicit priority with tie refusal, not arbitrary rule order. Above limits routes configured eligible independent review or refuses; no general exception, fixed-contract departure or margin disclosure. |
| **A6 — Maker/checker and exceptions** | Universal second person (burdens small firms); none (unsafe for governed cases); policy-specific separation (existing model). | Same person can prepare/issue routine quote where policy NONE, but cannot self-approve a step requiring independence. | Keep0070 NONE/POLICY/REQUIRED and stored-actor separation; distinct submitter/decider/principal counts and actual current eligibility. Commercial and operational review profiles remain independent. No initial risk/contract exception bypass; future exception only named exceptionable rule, exact facts/expiry and separate authority. | Approval capability alone neither issues nor grants cost/reason view. Request-specific projection0085; history/limit/reason readership0087. Reuse delegate limits, do not add delegate/principal ceilings. |
| **A7 — Non-HTTP, audit, imports and replay** | HTTP-only grants/system superuser workers (simple, bypass); all writers through guarded Actions with explicit limited context (required). | R8 worker renders one pinned manifest; cannot accept business or browse another company's evidence. | Human initiator and constrained service execution identity, tenant/subject/purpose/fence/policy evidence. Current authority rechecked for new execution/replay; exact completed receipts projected safely. Imports capture real occurrence vs recording, use same claims/duplicate rules; no fabricated acceptance from Order/import. | Append audit facts and after-commit optional events; durable required protocol only where approved. Revocation fences future work; already in-flight external effect is recorded truthfully. Console/bootstrap/demo included in writer inventory; no secret payload logs. |
| **A8 — Accepted business, partial wins and remainder** | Auto-WON on first acceptance (inflates); one OPEN amount (loses split truth); independent accepted/outcome/remainder facts (clearer). | Accepted40 of100 plus unoffered60 is partial business; Order/Job/delivery stages do not add value. | Accepted amount from0125/0126 plus authorised0132 corrections, never quote/email/forecast/fulfilment. Show partial-accepted indicator, offered eligible remainder, claim-blocked scope and unoffered potential separately. Explicit authorised pursuit closure only with disposition of remaining intent; no automatic whole-WON/LOST or offer withdrawal/claim release. | Reuse0107: accepted40 plus unsuccessful60 with no remainder pursued can close WON with recorded remainder disposition. Partial win remains a derived indication, not a new terminal outcome. Active accepted business prevents wholly LOST/ABANDONED classification; closure does not require withdrawing every quote. Owner reviews reporting/closure implementation, not settled outcome vocabulary. |
| **A9 — Remaining forecast and valuation** | Sum all revisions (double count); maintained estimate and selected eligible set (accepted0101); live FX total on read (rewrites history). | Accepted40 with maintained remaining estimate600.0000 does not silently subtract; SELECTED_OFFERS removes accepted capacity from selected unchanged source. | Reuse MAINTAINED default/SELECTED_OFFERS; maintained acceptance flags review, selected source never silently swaps revision. Publish remaining basis separately from accepted, snapshot explicit date/currency/source rates and base values; no probability weighting initially. Proposed fallback: exact dated rate by default, bounded prior-rate fallback only by explicit policy/max-age, never future rate; missing yields incomplete valuation. | R4 conversion/rounding adapter, CA effective quantities/budgets and R5 availability. Combined accepted+remaining view requires one explicitly disclosed comparable management valuation date/basis, not mixing historical quote FX with new remaining FX. This is not GL revenue/revaluation. |
| **A10 — Trust triggers, history and reporting privacy** | Technical recovery auto-trusts (contradicts0102); every rate movement invalidates (burdens); classified diagnostics with persistent review (accepted basis). | Expired selected offer excludes that remaining contribution; new later-date FX does not invalidate old dated publication. | Apply trigger table below, append trust transitions and reviewed publication versions; provisional never silently returns. Report effective-as-of AND known-as-of separately; no unrecorded reconstructed historical forecast. Scope reports before aggregate/export, omit hidden contributions and label visible-scope totals; no hidden total subtraction leaks. | Owner approves rate-correction/materiality and historical knowledge rules. Publication locks/version-checks claims, selected sources, policy, FX/correction generation; invalidation discoverable before trusted response. No report correctness claim from cron alone. |

## Proposed vocabulary — no registration or grant yet

Exact strings below are proposals for final approval; grouping commas denote distinct duties,
not wildcard support. Owning module retains existing permission names where they already exist.

| Area | Proposed/retained capability strings | Implications and exclusions |
|---|---|---|
| CRM existing | `sales.pipeline.view`, `sales.lead.manage`, `sales.opportunity.manage`, `sales.opportunity.assign`, `sales.opportunity.close`, `sales.stage.manage`, `sales.numbering.manage`, `sales.opportunity.attribution.correct` | Retain current meanings/independent attribution read and scopes; no new quotation rights |
| Quotation read/workflow | `sales.quotation.view`, `sales.quotation.commercial.view`, `sales.quotation.prepare`, `sales.quotation.issue`, `sales.quotation.withdraw`, `sales.quotation.history.view` | Prepare includes ordinary review submission under QL; no separate submit capability initially. Issue/withdraw/approval remain separate. Each operator gets minimum necessary projection within own scope, not unrestricted commercial/evidence read. |
| Quotation review | `sales.quotation.approve`, `sales.quotation.review-operational` | Exact commercial/operational approval-subject capabilities; request evidence under0085, configured review not always mandatory. No transition implied. |
| Pricing | `sales.pricing.configure`, `sales.pricing.eligibility.manage`, `sales.pricing.agreement.manage`, `sales.pricing.terms.view`, `sales.quotation.discount`, `sales.quotation.price.override`, `sales.quotation.discount.approve` | Reconcile PR slice names: configuration/Party eligibility/contract maintenance/confidential terms separate from routine discount and reasoned final-net override. Above-limit discount review is exact policy eligibility, not risk/fixed-contract bypass. Reuse actual source cost/margin gates; no new duplicate read. |
| Sensitive exception future | `sales.pricing.exception.approve` | Reserved proposal only; not initial registry/public surface or automatic grant; control-specific contract first |
| Customer decisions | `sales.customer-decision.view`, `sales.customer-decision.evidence.record`, `sales.customer-decision.accept`, `sales.customer-decision.reconcile`, `sales.customer-decision.correct`, `sales.customer-decision.claim-release`, `sales.customer-decision.evidence.view` | Independent legitimate subject/evidence read; correction/late/claim release sensitive. Claim-release execution remains deferred0132. Evidence recorder does not accept. |
| Handover | `sales.handover.view`, `sales.handover.prepare`, `sales.handover.release`, `sales.handover.hold`, `sales.handover.amend` | Preparation/release/hold/operational revision distinct; shared review uses typed subject profile; actual target/warehouse/Inventory duties remain owning module. No invented `sales.*` stock posting permission. |
| Sales document adapter | `sales.document.view`, `sales.document.generate`, `sales.document.repair`, `sales.document.download`, `sales.document.attachment.manage`, `sales.document.attachment.view` | Module subject/profile and confidential file access remain Sales; no sales.document.template.manage or generic documents.download. Repair authorises owner-approved semantic rendition, not host recovery. Print uses permitted download/invocation, no receipt inference. |
| Shared Documents configuration | `documents.template.manage`, `documents.page-policy.manage` | Documents owns shared curated versions/defaults/activation under0137; does not grant quote prepare/issue or any subject/file read. Separate template content versus page/resource policy; tenant cannot change runtime security caps. |
| Technical recovery | `documents.recovery.reconcile`, `documents.recovery.restore` | Current company/type/intent recovery context and minimum metadata. No file-read-all, business repair or permission bypass; restore exact existing identity/hash and preserve audit. Service execution identity separately constrained. |
| Company brand | existing `company.profile.manage` | Extend Company-owned governed brand/version operation with this existing identity duty and safe asset handling; Documents does not duplicate identity edit rights. Existing Company history rules remain. |
| Sales association/configuration | `sales.quotation.associate`, `sales.quotation.policy.manage`, `sales.financial.configure` | Current CRM reassociation requires both relevant scopes and immutable0099 issue linkage; configuration follows existing QL/FR owning boundaries, not pricing/consent/file grants. |
| Delivery | `sales.delivery.view`, `sales.delivery.record`, `sales.delivery.send`, `sales.delivery.reconcile` | Initial record is manual only; send/provider reconcile dormant until approved adapter; read masks confidential recipient/evidence facts appropriately |
| Forecast/reports | `sales.forecast.view`, `sales.forecast.maintain`, `sales.forecast.select`, `sales.forecast.review`, `sales.forecast.publish`, `sales.forecast.value`, `sales.report.view`, `sales.report.export` | Maintain/select by enabled method; valuation/publish do not clear commercial review. Report/export scopes/field projection no wider than their sources. |

Avoid granting record-wide commercial read solely because someone can perform one exact request review;
approve minimum review projection through existing subject adapter. Quote commercial reader cannot
read actual costs or confidential competing offers by linked Party. Policy manager cannot grant itself
business acceptance/exception rights or change other users' limits without existing administration duty.
Unneeded dormant strings stay unregistered until supported delivery needs them, with reserved design
documented. Owner may consolidate genuinely identical read purposes after inspecting exposure needs.

## Proposed scope/grant migration contract and examples

Each proposed name gets owner module, purpose, minimum read, sensitive profile, supported scope,
HTTP/Action/non-HTTP surfaces, predecessor evidence, holders and automatic-grant exclusion classification.
New duties have no inferred incumbents. Existing CRM grants preserved, independent quotation enablement
is explicit. Idempotent migration records per-company role/direct origin, before/after scope and approval
reference; cannot copy a restricted role into unrestricted direct permission. Access resolver additive
union is not denial: users with an unrelated broad grant may still be broad under existing semantics.
Show effective result to administrator before assigning; no implied deny or name-based administrator.

Registered true full-access mandate can include designated new sensitive duties only under approved
mandate and actual scope evidence, preserving0114. Missing/future/foreign mandate excludes. Explicit
existing excluded grants survive re-sync. No live person is assigned by research; owner approves holder
inventory, feature-specific rollout manifest and staging verification. Ordinary duty migration may
preserve demonstrated prior ability only; new feature does not mean everyone previously an editor
had its sensitive duty. Re-run/no-op, negative-holder, cross-company and scope-preservation proofs required.

Examples: a technical reviewer may see exact scope/destination without totals; commercial reviewer
sees relevant quote money, not inventory valuation. A correction specialist needs acceptance correction
AND source decision read, each within its scope; download capability does not open every attachment.
A service operator's warehouse permission is irrelevant unless path actually touches stock. A company
administrator may manage eligible assignments but receives no actual customer consent authority by title.

## Proposed forecast trust and reporting detail

| Event | Proposed classification and action |
|---|---|
| Acceptance/authorised reduction changes selected remaining capacity | Recompute eligible quantity/value using delivered adapter; invalidate obsolete publication basis and persist review-needed; no silent republication. MAINTAINED value unchanged, flagged for review. |
| Selected expiry/withdrawal/replacement/claim block or changed choice/dependency | Ineligible contribution excluded from trusted current totals, reasons coexist; whole forecast provisional if its whole basis incomplete; original selected references retained. New draft alone not supersession. |
| Missing required conversion/control/quantity evidence | Incomplete/invalid; show permitted known contributions explicitly, never unknown=zero or subtotal labelled complete |
| Technical recovery/restored eligibility | Validation may recover, trust does not; successful authorised review/publication needed to re-enter trusted totals under0102 |
| Ordinary later-date FX movement | No invalidation of trusted historical dated valuation; optional indicative value separate |
| Correction/invalidation of rate actually used | Preserve original amounts/provenance; if relied-on value/date/source validity changes, provisional pending impact review, even if source later recovers. Pure metadata with no relied-on evidence change warns only. Recommend no automatic materiality tolerance initially: any relied-on value/date/source-validity change requires review. Metadata-only warning is allowed only when reliance evidence is unchanged. |
| Current responsibility/reassociation changes | Preserve original publication and issue attribution; review where maintained assumptions/selection eligibility/scope actually affected, no automatic business amount rewrite |
| Delivery acknowledgement/Job/Order/dispatch/invoice | No independent new acceptance or forecast win; use owning authoritative accepted/actual sources only |

Accepted quantities/values aggregate original agreement plus supported corrections exactly once by
commercial component. Original benefit consumption, effective obligation and operational allocations
are separate measures. SELECTED_OFFERS uses only eligible offered unaccepted disjoint scope within a
non-competing selection set; no arbitrary fractional Assembly/fixed tax/discount arithmetic. Unsupported
remaining valuation is explicit incomplete, never fabricated prorating. Unoffered potential belongs
to an explicit maintained assumption, not selectable fictitious quotation lines. A maintained600.0000
and selected400.0000 cannot be added for the same intent just because both are stored.

PublishForecast freezes source selection/maintained assumptions, quantities/components, policy,
currency/date/rates/fallback provenance, rounding and known evidence versions under canonical Sales
coordinator→roots→publication locks. Append receipt/publication/trust events atomically. Concurrent
acceptance/withdrawal/rate correction must either precede revalidation or invalidate before trusted
read; polling alone cannot advertise known-stale data as trusted. All FX/master writers affecting
relied evidence need delivered generation/invalidation integration or feature refuses enablement.

Reports distinguish historical published valuation, current trusted, provisional/incomplete, and
optional indicative value. Proposed default history is known-as-of server recorded time, with separate
effective business date filter for late evidence; never pretend a later-recorded PO was known earlier.
Restated view identifies restatement/version and preserves original report/publication. Explicit single
management conversion policy needed for combined accepted+remaining amount; show source currencies when
incomplete or incompatible. No tax/revenue/realised FX/GL authority is conferred. Limits and reports use
their own pinned conversion bases; forecast FX cannot alter quoted/accepted monetary evidence.

## Final owner choices and delivery boundaries

Review A1–A10, then exact names/minimum read implications; company-wide first versus a concrete
restricted scope rollout; sensitive automatic-grant exclusions and eligible mandates; actual amount/
discount policy precedence/reference/limits; partial-win reporting/closure evidence under0107; dated fallback-age
policy, used-rate correction treatment and known-as-of/restatement presentation. No requirement to
name every real holder now, but holder inventory and tested safe grants are required before rollout.

After approval specify vocabulary/field/scope matrix → safe registry/grants → guarded source duties →
report measures → forecast basis/valuation/trust/publication → integrated rollout. No accepted R9 ADR
or implementation-ready grant migration is asserted by this final review proposal. Later delivery must
include real all-writer/scope inventory, migrations/ratchets/generated API/UI/demo and race evidence.
R5/R6 partial-family restrictions, R7 downstream impact adapters and R8 email/portal/security gates stay.

## Framework0137 applied — no additional R9 architecture gate for ownership

Documents/Company own shared configuration/recovery, Sales owns subject/download/attachment/delivery
eligibility, common protected stream calls current adapter. No extra ordinary generic download duty,
no config/recovery read-all. Final vocabulary above replaces the earlier conflicting Sales template
proposal. Approval of0137 does not accept these exact new strings or grant any user. Framework first
pilot and secure file availability are actual delivery dependencies for R8, not for independent
pricing-limit/forecast design. Retention decisions and confidential associations remain module-owned.

## Final scope and operation profiles — recommendation, not deployed authority

| Operation/profile | Required current eligibility | Explicit limit |
|---|---|---|
| Quotation list/show/customer money | sales.quotation.view plus independent commercial-view for general monetary projection, each effective scope; workflow may provide exact minimum operation projection under its own approved duty | No margins/costs/private competing terms or source directory expansion; row includes only permitted facts |
| Prepare/issue/withdraw/associate | Exact named duty and legitimate quotation/Party/source access for that operation; changed association checks destination Opportunity eligibility and original link preserved | Prepare includes ordinary submit; replacement needs issue plus withdrawal if withdrawing predecessor; no silent terms/price override |
| Review/discount approval | Exact review capability, approval assignment/ceiling/profile/steps and stored actor independence under0070/0085 | Request-specific evidence, not broad quote read; discount approval not fixed-contract/risk bypass;0087 reason/limit guards |
| Record evidence versus accept/reconcile/correct | Distinct evidence record/accept/late reconcile/correct duty plus independent decision read and confidential evidence view when required | Record observation creates no claim/hold; late/correction need real R7 impact/disposition adapters; claim-release execution deferred |
| Handover/warehouse/site | Exact prepare/release/hold/amend duty plus independent source/target operational projection and owning warehouse/site operation eligibility where path actually uses it | No universal site/warehouse/Job requirement for service paths; stage does not consume commercial root again |
| File generation/download/attachment | Sales operation and current subject/audience/profile/association access; shared Documents rechecks tenant/health/binding | Full original requires whole payload eligibility; otherwise explicit permitted profile/rendition or refusal, never renamed redaction |
| Shared configuration/Company/recovery | Owning configuration duty and company scope; recovery minimal type/intent context, no business content grant | No new automatic holder/role inference; original and snapshots unmodifiable through settings; runtime security caps outside company-editable templates |
| Forecast/report/export | Exact operation duty plus current eligible source/profile scopes; filter before sums, label visible-scope total | Unseen totals/hidden conflict details never leak; export cannot exceed read, no complete label on incomplete valuation |

Initial supported mode is company-wide only through explicit effective per-duty rights, consistent
with current0106; restricted own/assigned/unit modes remain refused with403 until each surface has
real integration. For a supported restricted mode, out-of-scope IDs use existing404 conventions.
No field history/export/download backdoor, global Eloquent filter substitute or unrelated all-scope
grant rescue. This limits initial rollout, not permanently forbids later scoped Sales delivery.

## Limit/forecast refinements ready for owner decision

1. Authority reference is the governed versioned pre-discretionary commercial reference from R3,
   with compatible measure/currency. Preserve automatic benefit comparison, proposed discretionary
   input and aggregate final reduction separately. No denominator/reference reset via manual base,
   line splitting or new revision. Percentage and absolute limits both apply where configured;
   no additive actor/principal/delegate ceilings. Select compatible grant coverage, then every
   independent mandatory control applies. Unknown/nonpositive/unsupported reference or required
   cost/FX refuses; do not invent unlimited defaults or a universal numeric threshold.
2. Use explicit highest-priority rule-set selection with ambiguous tie refusal; approval engine
   routing remains0070. Actual threshold values are Company-governed configuration in owning Sales
   policy, not requested as universal owner constants. Same-total changes to basis/source/terms/
   actor authority invalidate prior evaluation/approval at issue. Exceptional controls stay off.
3. Forecast SPOT: recommend company-base bridge for mixed source/reporting currencies with each
   manual-rate leg at explicit valuation date, identity=one; default exact-date, opt-in bounded
   latest-on-or-before/max-age, never future/arbitrary route. Pin rates/dates/source/route/policy,
   use R4 decimal/rounding primitives without changing quotation FX selection or old publications.
4. Combined accepted+remaining management view uses one explicit valuation currency/date/policy
   and effective accepted source components plus disjoint remaining basis. Keep separate accepted
   commercial dual amounts and management conversion versions; no recognised revenue implication.
   If a compatible complete valuation cannot be made, show separate source-currency contributions
   and incomplete status, never sum historical quote FX with current remaining FX.
5. Changed/inactivated relied-on rate evidence forces persistent review regardless of amount
   materiality initially; ordinary later-date rate does not. Metadata-only unchanged relied facts
   warns. History defaults known-as-of recorded time plus separate effective-as-of/restated view.
   No historic forecast fabricated where publication evidence did not exist. Partial-win/closure
   meaning already0107; owner approves reporting implementation, not a new terminal outcome.

## Safe-grant classification and rollout contract

Keep eight incumbent CRM rights and0114 independent read unchanged. No automatic CRM→new pricing/
quote/acceptance grant. New sensitive accept/reconcile/correct/claim-release/discount-approval/risk,
agreement/configuration/policy/template/page/recovery and confidential-evidence rights are excluded
from automatic vocabulary growth by default; explicit authorised grant or verified qualifying
full-access mandate only, preserving actual duty/company/scope. Other newly exposed duties still
need an approved holder recipe; safe registration alone does not enable business access. Keep dormant
future risk/contract departure/claim-release execution unregistered or explicitly reserved without
public writers as actual slice contract requires. No new wildcard/deny/admin-role engine.

For genuine retrofit continuity, map exact predecessor→new permission to each company role/direct
holder and retain its scope; do not copy restricted role to unrestricted direct grant. New business
feature has no inferred incumbent. Review effective additive union and authorised holder inventory
before rollout; full-access mandate approval must match actual company assignment and evidence,
not email/role name/permission count. Explicit excluded grants survive re-sync. Audit all registry,
sync/bootstrap/import/console/demo consumers; idempotent no-op, negative-holder and cross-company
tests plus staging review required. No live holder is assigned by planning approval.

Human commands/replays check current human authority; service execution uses current bounded service
authority tied to persisted company/type/subject/purpose intent, records original initiator and never
impersonates an inactive employee. Legitimate committed issued-original archival work may complete
under independently authorised system workflow; no revoked human grant is silently reused to send
or accept. External in-flight result remains truthful after revocation. Trust/claim writers require
canonical source generations/invalidation before a current trusted response; polling alone fails.

## One consolidated owner review request

Approve/amend A1–A10 together with the reconciled exact vocabulary, scope/operation profiles,
limit/FX/history refinements and safe-grant exclusions above. Remaining business choices are
company-wide initial exposure versus a separately specified restricted rollout, exact duty/read
implications, automatic-growth classification, reference/priority rules, forecast bridge/fallback,
used-rate correction policy and known/effective-as-of presentation. Company-specific numbers,
named real holders and supported-adapter rollout inventory remain explicit deployment/configuration
decisions; not implied by architectural approval. No final R9 ADR accepted yet; Q49 READY_FOR_OWNER.

After owner approval record R9 ADRs/specifications, then separately authorise implementation sequence:
registry/safe grants/scopes → core source/transition duties → document pilot/shared services →
report measures → forecast basis/valuation/trust/publication → integrated staged rollout. Independent
source/forecast foundation may stage without renderer, but public surfaces require full actual scope,
grants/guards/field policies and all affected adapters. R5/R6 partial-family/R7 downstream/Finance/
Phase5 restrictions remain unchanged. Required future proof includes Action/HTTP/SQL/service denials,
concurrent authorisation and publication invalidation, immutable evidence, migration cycle and actual
API/UI/demo/staging delivery. This final review is documentation, not application verification.

## Repository coordination note

Resolved 2026-10-15: develop was rebased onto origin/develop and the R7 handover ADR, which collided with the backup ADR 0130, was renumbered to 0136. 0130 is the backup decision; R7 is 0131–0132 and 0136.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [R8 slices](phase-4-r8-implementation-slices.md)
- [Authorisation](../architecture/authorization.md) · [Approvals](../domain/approvals.md)
- [0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md) · [0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md)
- [0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md) · [0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md) · [Questions](../OPEN-QUESTIONS.md)
