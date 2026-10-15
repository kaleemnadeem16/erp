[← Phase index](README.md)

# R9 — Sales authority, reporting and forecast integration

**2026-10-15 · INITIAL CONSOLIDATED REVIEW / PROPOSED / planning Q49 OPEN.**
R1–R8 authority is reused, not reopened. No R9 ADR accepted; names, rollout and reporting refinements
below require owner review before final specifications. No application coding or real grants authorised.
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

## Initial business-first decision matrix

| Bundle | Options/trade-offs | Practical example | Recommendation for owner review | Dependencies/boundaries |
|---|---|---|---|---|
| **A1 — Duty vocabulary across R1–R8** | One quotation-manage duty (easy, collapses authority); duty vocabulary (precise, more configuration); one permission per table/field (unusable). | Drafter prepares100; authorised issuer issues; acceptance recorder cannot revise price or release Job. | Retain eight existing CRM strings; approve proposed business-duty catalogue below, with ordinary read/minimum workflow projection distinct from sensitive transitions. Reuse existing owning-module duties instead of new Finance/Inventory/Approvals synonyms. | Owner approves exact names/implications, not real users. R3–R8 Request/Action and UI coverage follows actual delivery; no hidden general manage/bypass. |
| **A2 — Record scope and relationship access** | Company-wide first (simple); full restricted Sales scopes (work); inherit owner/Party/target access transitively (unsafe). | Warehouse operator sees eligible delivery destination, not every Party's credit terms or all quotations linked to same customer. | Company-wide only through explicit effective per-duty grant first; unsupported own/assigned/unit modes refuse as0106. Implement restricted paths only with complete list/count/lookup/history/export/mutation coverage. Require each sensitive transition's duty scope AND legitimate source/target projection; unrelated unrestricted duty cannot widen it. | Reuse RecordScope, no second team/tenant resolver. Party candidate selector is narrow workflow access, not directory/Finance grant. No universal live Item/Assembly read solely to see a lawful customer snapshot; owning adapter defines necessary current-source access for mutations. |
| **A3 — Sensitive fields and attachments** | View record→all data (easy, leaks); server projections and independent file access (precise); scrub text automatically (unreliable). | Cost-blind issuer gets required control outcome, not cost/margin threshold or confidential competitor existence. | Reuse existing cost/Finance read gates; separate commercial terms from protected costs/margins and internal evidence. Customer projection is fixed allowlist. R6 attachments/recipient diagnostics and approval reasons use need-to-know profiles/0087 rules, not generic history licence. | Exact Resource/fields/filter/sort/count/print/download/log matrix before public rollout. No scalar zero for redacted cost, no protected operands in errors/hash/comparison candidates. Secure R8 adapter actually delivered. |
| **A4 — Grants and full-access mandates** | Grant every new duty to editors/admin names (quick, escalation); preserve proven incumbents and explicit mandates (safe, needs mapping); revoke broadly on deploy (disruptive). | Existing Sales manager may prepare; cannot acquire retrospective acceptance correction through registry growth. | Map each new capability to exact legitimate previous duty/holder if evidence exists, keeping same company and scope. New sensitive acceptance/correction/late-reconcile/claim-release/exception and authority-policy duties default excluded from automatic growth; explicit authorised assignment or verified full-access mandate only. Existing0114 treatment unchanged. | No current permission-count/name inference. Audit registration, grant migrations, sync/bootstrap/demo/seed/import and scoped role→direct conversions. Mandate must cover actual duty/company/scope; missing proof excludes. Holder decision remains owner's operational step. |
| **A5 — Effective amount/discount authority** | Universal percentage (too coarse); actor limits against current edited base (reset bypass); exact policy reference/input+aggregate controls (sound, more evidence). | Governed reference100.0000; automatic10%, discretionary20% gives80.0000: aggregate20%, not just extra10%. Editing base cannot erase reference. | Pin authoritative reference and discount basis, both specific input and aggregate reduction; apply percentage and absolute ceilings independently, compare amount ceilings in company base using frozen rate/date/source under0070/R4. Missing required reference/FX/cost blocks, no inferred unlimited authority. Overlapping policy conditions need explicit precedence; unknown/conflicting match refuses. | Limits are typed policy/assignments, never permission strings. Owner supplies actual values and supported policy precedence. Above limits routes configured eligible independent review or refuses; no general exception, fixed-contract departure or margin disclosure. |
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
| Quotation read/workflow | `sales.quotation.view`, `sales.quotation.commercial.view`, `sales.quotation.prepare`, `sales.quotation.submit`, `sales.quotation.issue`, `sales.quotation.withdraw`, `sales.quotation.history.view` | Prepare includes ordinary draft submission already specified in QL: recommend reuse prepare for submit initially, so standalone submit remains deferred unless owner wants independent submitter. Final names must avoid contradictory double gate. Each operator gets minimum necessary projection within own scope, not unrestricted commercial/evidence read. |
| Quotation review | `sales.quotation.approve`, `sales.quotation.review-operational` | Exact commercial/operational approval-subject capabilities; request evidence under0085, configured review not always mandatory. No transition implied. |
| Pricing | `sales.pricing.discount`, `sales.pricing.override`, `sales.pricing.agreement.depart`, `sales.pricing.policy.manage` | Routine discount vs reasoned final-net override vs explicit contract departure; contractual departure stays unsupported without separately delivered contract. Reuse estimating internal-cost read; independent margin view only if actual separate need is approved; no duplicate cost gate invented. |
| Sensitive exception future | `sales.pricing.exception.approve` | Reserved proposal only; not initial registry/public surface or automatic grant; control-specific contract first |
| Customer decisions | `sales.customer-decision.view`, `sales.customer-decision.evidence.record`, `sales.customer-decision.accept`, `sales.customer-decision.reconcile`, `sales.customer-decision.correct`, `sales.customer-decision.claim-release`, `sales.customer-decision.evidence.view` | Independent legitimate subject/evidence read; correction/late/claim release sensitive. Claim-release execution remains deferred0132. Evidence recorder does not accept. |
| Handover | `sales.handover.view`, `sales.handover.prepare`, `sales.handover.release`, `sales.handover.hold`, `sales.handover.amend` | Preparation/release/hold/operational revision distinct; shared review uses typed subject profile; actual target/warehouse/Inventory duties remain owning module. No invented `sales.*` stock posting permission. |
| Documents | `sales.document.view`, `sales.document.generate`, `sales.document.repair`, `sales.document.download`, `sales.document.template.manage`, `sales.document.attachment.manage`, `sales.document.attachment.view` | Original customer projection does not open internal attachment; independent subject/file scope. Initial print uses download plus local invocation record, no fake receipt. |
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
| Correction/invalidation of rate actually used | Preserve original amounts/provenance; if relied-on value/date/source validity changes, provisional pending impact review, even if source later recovers. Pure metadata with no relied-on evidence change warns only. Owner may request separate materiality policy; no silent threshold assumed. |
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

## Important owner choices and next delivery boundaries

Review A1–A10, then exact names/minimum read implications; company-wide first versus a concrete
restricted scope rollout; sensitive automatic-grant exclusions and eligible mandates; actual amount/
discount policy precedence/reference/limits; partial-win reporting/closure evidence under0107; dated fallback-age
policy, used-rate correction treatment and known-as-of/restatement presentation. No requirement to
name every real holder now, but holder inventory and tested safe grants are required before rollout.

After approval specify vocabulary/field/scope matrix → safe registry/grants → guarded source duties →
report measures → forecast basis/valuation/trust/publication → integrated rollout. No accepted R9 ADR
or implementation-ready grant migration is asserted by this initial proposal. Later delivery must
include real all-writer/scope inventory, migrations/ratchets/generated API/UI/demo and race evidence.
R5/R6 partial-family restrictions, R7 downstream impact adapters and R8 email/portal/security gates stay.

## Independent progress and shared framework dependency — proposed Q50

Continue A4–A10 grants/limits/review/audit/partial-win/forecast work against accepted authority while
[shared document ownership](../architecture/document-print-framework-review.md) is reviewed. A1/A3
document/template/file rows remain proposals: recommend common template/page administration under
shared Documents, authoritative company brand under Company, business download/evidence/read/send
under owning modules. Framework delegates to tenant/subject/audience access adapters; no extra global
download capability or file-read-all operator grant. Attachment storage/validation/recovery is shared,
confidential evidence association and access remain module policy. A full original cannot be returned
to a quantity-only reader merely because the blob exists. R9 finalisation/rollout manifest must resolve
these boundaries; no new string/grant is accepted here. Limits and forecast require no renderer engine.

## Repository coordination note

At start local develop b3dae82 was2 ahead/7 behind recorded origin/develop. Origin commit3e8a31a
uses0130 for backups; local0130 is R7 handover. No history reconciliation, renumbering, fetch/push,
merge/rebase/reset/cherry-pick performed. Owner/implementation agent coordinates eventual numbering
and integration under AGENTS rules; use full ADR filenames meanwhile. Local next0133 was free on
both inspected histories and allocated to R8 in this work; this does not solve existing collision.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [R8 slices](phase-4-r8-implementation-slices.md)
- [Authorisation](../architecture/authorization.md) · [Approvals](../domain/approvals.md)
- [0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md) · [0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md)
- [0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md) · [0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md) · [Questions](../OPEN-QUESTIONS.md)
