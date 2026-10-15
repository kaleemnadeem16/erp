[← Domain index](README.md)

# R9 — Canonical duty × operation × scope × field × holder matrix

**2026-10-15 · ACCEPTED0138–0140 / SPECIFIED, no new registry/grants IMPLEMENTED or VERIFIED.**
This is the exact delivery vocabulary for R3–R9/DF, subject to pre-registration code/owner collision
check. One permission has one module owner; no permission represents a role, amount band or record ID.
No `sales.quotation.submit`, `sales.document.template.manage`, `documents.download` or file-read-all.

Scope C = current company plus effective RecordScope for the exact duty; Q/P = quotation/Party
and legitimate source projection. Initial new Sales scope supports explicit company-wide only;
restricted own/assigned/unit scope refuses403 until all surfaces genuinely integrate. Supported
out-of-scope ID conventions remain404. Related Party/assignment/ownership does not confer access.
For operation-specific minimum projections use registered legitimate workflow access, not a new
general directory/Finance grant. General commercial/history/file access does not follow automatically.

Grant classifications: E = retain existing exact contract/holders, no new assignment; E-S = existing
sensitive0114 exclusion. N = new named ordinary holder recipe explicitly approved at rollout, no
CRM/editor continuity inferred. S = sensitive, excluded from ordinary automatic growth; explicit
authorised holder or qualifying approved full-access mandate with scope proof. D-S = dormant/reserved
sensitive design, do not automatically register/enable. DOC6 duties stay dormant until actual adapter
contract/rollout. Classifications do not name real people/roles or authorise a migration now.

| Exact duty | Owner | Operation | Record/relationship scope | Sensitive projection | Holder/grant class | Delivery gate |
|---|---|---|---|---|---|---|
| `sales.pipeline.view` | Sales | Pipeline list/detail/note/history | C + existing SalesAccess/RecordScope | CRM-safe | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.lead.manage` | Sales | Record/qualify/disqualify/link/convert Lead | C + existing SalesAccess/RecordScope | CRM-safe | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.opportunity.manage` | Sales | Create/update/stage/note/next action | C + existing SalesAccess/RecordScope | CRM-safe | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.opportunity.assign` | Sales | Assign/reassign eligible active member | C + existing SalesAccess/RecordScope | CRM-safe | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.opportunity.close` | Sales | Lost/abandoned/reopen; future accepted WON under0107 | C + existing SalesAccess/RecordScope | CRM-safe | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.stage.manage` | Sales | Configure open stages/history | C + existing SalesAccess/RecordScope | Config | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.numbering.manage` | Sales | Existing Sales numbering formats | C + existing SalesAccess/RecordScope | Config | E | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.opportunity.attribution.correct` | Sales | Append historical attribution correction + independent CRM read | C + existing SalesAccess/RecordScope | Attribution | E-S | Existing CRM only; future WON waits authoritative CA/RP |
| `sales.quotation.view` | Sales | Quotation list/detail safe metadata | C + Q/P/source eligibility; each duty independently | Quote-min | N | QL1 |
| `sales.quotation.commercial.view` | Sales | General chosen-commercial figures | C + Q/P/source eligibility; each duty independently | Commercial | S | QL1/FR5 |
| `sales.quotation.prepare` | Sales | Draft/revise/cancel-draft/preview/ordinary submit | C + Q/P/source eligibility; each duty independently | Commercial-workflow | N | QL1/2 + PR/FR |
| `sales.quotation.issue` | Sales | Official issue | C + Q/P/source eligibility; each duty independently | Commercial-workflow | N | QL3 |
| `sales.quotation.withdraw` | Sales | Eligible unaccepted withdrawal/replacement disposition | C + Q/P/source eligibility; each duty independently | Commercial-workflow | S | QL4/5; partial remains gated |
| `sales.quotation.history.view` | Sales | Quote lifecycle history | C + Q/P/source eligibility; each duty independently | Quote-min + independent fields | N | QL1–6 |
| `sales.quotation.approve` | Sales | Exact commercial review subject | C + Q/P/source eligibility; each duty independently | Review-commercial | S | QL2 + shared Approvals |
| `sales.quotation.review-operational` | Sales | Exact operational review profile | C + Q/P/source eligibility; each duty independently | Review-operational | S | QL2 + review adapter |
| `sales.quotation.discount` | Sales | Routine specific discretionary input | C + Q/P/source eligibility; each duty independently | Commercial-workflow | N | PR4/5 + AU3 limits |
| `sales.quotation.price.override` | Sales | Reasoned final net override | C + Q/P/source eligibility; each duty independently | Commercial-workflow | S | PR4/5 + AU3; no risk bypass |
| `sales.quotation.discount.approve` | Sales | Exact above-limit discount subject | C + Q/P/source eligibility; each duty independently | Review-commercial | S | AU4 + supported policy |
| `sales.quotation.associate` | Sales | Current CRM association, original0099 preserved | C + Q/P/source eligibility; each duty independently | CRM + quote association | S | QL association Action + both source/destination eligibility |
| `sales.quotation.policy.manage` | Sales | Validity/unknown-payment configuration | C + Sales policy configuration; no live quotation required | Config | S | QL0/4/6 |
| `sales.pricing.configure` | Sales | Version supported base/rule/plan/control policy | C + pricing configuration; no live quote or blanket Party directory | Config writable terms | S | PR1–4/6 |
| `sales.pricing.eligibility.manage` | Sales | Dated Party pricing memberships | C + independent legitimate Party/membership eligibility | Party eligibility | S | PR2 |
| `sales.pricing.agreement.manage` | Sales | Contractual pricing versions | C + contracting Party and protected agreement/term scope | Confidential terms | S | PR2/3; cannot depart accepted terms |
| `sales.pricing.terms.view` | Sales | Read otherwise protected term choices | C + legitimate Party/agreement/private-term eligibility | Confidential terms | S | PR2/3 |
| `sales.financial.configure` | Sales | Sales FX/rounding policy | C + Sales financial policy configuration; not FX master edit | Config | S | FR1/4 |
| `tax.configuration.manage` | Shared Tax domain (FR2) | Typed tax component/group/rate versions | C + shared Tax configuration; no quotation prerequisite | Tax-config | S | FR2/3/6 owning tax provider |
| `tax.profile.manage` | Shared Tax domain (FR2) | Party tax profile and evidence | C + independent legitimate Party/tax-evidence eligibility | Tax evidence | S | FR2 + independent Party eligibility |
| `sales.customer-decision.view` | Sales | Decision/case safe detail/history | C + case/Party/quote; sensitive mutation AND independent decision read | Decision-min | N | CA1 |
| `sales.customer-decision.evidence.record` | Sales | Shared intake/append observations | C + case/Party/quote; sensitive mutation AND independent decision read | Evidence-write | N | CA1; file requires secure DF |
| `sales.customer-decision.accept` | Sales | Authoritative ordinary consent/claims | C + case/Party/quote; sensitive mutation AND independent decision read | Exact consent | S | CA2/3 |
| `sales.customer-decision.reconcile` | Sales | Late recognition | C + case/Party/quote; sensitive mutation AND independent decision read | Historical consent/impact | S | CA5 + affected HA |
| `sales.customer-decision.correct` | Sales | Governed accepted reduction | C + case/Party/quote; sensitive mutation AND independent decision read | Exact consent/impact | S | HA6/CA5 |
| `sales.customer-decision.evidence.view` | Sales | Confidential evidence/files | C + case/Party/quote; sensitive mutation AND independent decision read | Evidence-read | S | CA1 + file adapter |
| `sales.handover.view` | Sales/target adapter | Safe plan/readiness/target links | C + accepted source/target + owning warehouse/site where applicable | Operational | N | HA1 |
| `sales.handover.prepare` | Sales/target adapter | Prepare plan, no target/stock | C + accepted source/target + owning warehouse/site where applicable | Operational | N | HA1 |
| `sales.handover.release` | Sales/target adapter | Release exact supported component | C + accepted source/target + owning warehouse/site where applicable | Operational + baseline | S | HA2 + real target |
| `sales.handover.hold` | Sales/target adapter | Authorised operational hold | C + accepted source/target + owning warehouse/site where applicable | Operational | S | HA5 |
| `sales.handover.amend` | Sales/target adapter | Schedule/unused cancellation/reassignment | C + accepted source/target + owning warehouse/site where applicable | Operational/impact | S | HA4/5 |
| `sales.document.view` | Sales | Safe generation/artefact history/status | C + subject/purpose/audience/file association; independent confidential duty | Document-min | N | DF2/DOC1 |
| `sales.document.generate` | Sales | Request pinned generation/retry | C + subject/purpose/audience/file association; independent confidential duty | Customer render profile | N | DF3/DOC2 |
| `sales.document.repair` | Sales | Authorise labelled semantic rendition | C + subject/purpose/audience/file association; independent confidential duty | Customer render profile | S | DF4/DOC3 |
| `sales.document.download` | Sales | Original/permitted rendition/print invocation | C + subject/purpose/audience/file association; independent confidential duty | Exact whole payload | N | DF4/DOC4 |
| `sales.document.attachment.manage` | Sales | Validate/link allowed evidence attachment | C + subject/purpose/audience/file association; independent confidential duty | Association write | S | DF4 + CA/DOC |
| `sales.document.attachment.view` | Sales | Independent confidential file access | C + subject/purpose/audience/file association; independent confidential duty | Exact association | S | DF4 + owner adapter |
| `documents.template.manage` | Documents | Version/configure/activate shared curated templates/defaults | C + allowed type/intent/recovery binding | Config or minimal technical metadata; NO file read | S | DF0–4 |
| `documents.page-policy.manage` | Documents | Version/configure page profiles/defaults within hard caps | C + allowed type/intent/recovery binding | Config or minimal technical metadata; NO file read | S | DF0–4 |
| `documents.recovery.reconcile` | Documents | Reconcile bounded intent/object metadata | C + allowed type/intent/recovery binding | Config or minimal technical metadata; NO file read | S | DF0–4 |
| `documents.recovery.restore` | Documents | Exact-hash restore with audited reason | C + allowed type/intent/recovery binding | Config or minimal technical metadata; NO file read | S | DF0–4 |
| `sales.delivery.view` | Sales | Safe recipient/attempt history | C + quote/recipient/purpose/profile | Recipient/attempt; confidential proof independently | N | DOC4 |
| `sales.delivery.record` | Sales | Manual actual-channel evidence | C + quote/recipient/purpose/profile | Recipient/attempt; confidential proof independently | N | DOC4 |
| `sales.delivery.send` | Sales | External current/historical dispatch | C + quote/recipient/purpose/profile | Recipient/attempt; confidential proof independently | D-S | DOC6 supported provider only |
| `sales.delivery.reconcile` | Sales | Provider unknown-result disposition | C + quote/recipient/purpose/profile | Recipient/attempt; confidential proof independently | D-S | DOC6 durable protocol |
| `sales.forecast.view` | Sales | Forecast basis/trust/source view | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | N | RP1 safe basis; RP4 for published forecast; CA/QL sources |
| `sales.forecast.maintain` | Sales | Maintain explicit remaining assumption | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | N | RP1 + actual Opportunity; acceptance effects need CA/QL integration |
| `sales.forecast.select` | Sales | Choose eligible noncompeting issued set | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | N | RP1 + actual eligible QL/CA remaining-value adapters |
| `sales.forecast.review` | Sales | Explicit commercial trust review | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | S | RP3 + exact basis/source review |
| `sales.forecast.publish` | Sales | Publish reviewed immutable version | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | S | RP4 + all source writer invalidation |
| `sales.forecast.value` | Sales | Explicit dated management valuation | C + Opportunity/selected sources and permitted evidence | Forecast commercial; no hidden sources/FX-admin | S | RP2 + actual FR/Currency primitives |
| `sales.report.view` | Sales | Scoped measures/known/effective-as-of reports | C + visible source/profile before aggregation | Report-safe; no hidden totals | N | RP0 for implemented accepted measures; RP5 for history/forecast |
| `sales.report.export` | Sales | Same permitted report projection exported | C + visible source/profile before aggregation | Report-safe; no hidden totals | N | Same delivered report as read + AU export guard; RP5 history/forecast |
| `company.profile.manage` | Company | Identity/brand version/assets | C + existing Company profile guard | Brand/profile only | E | Reuse exact owner contract; no automatic Sales transfer |
| `currency.exchange-rate.manage` | Currency | Manual rate maintenance; invalidate used generation | Existing Currency contract | Rate data, no forecast publish | E | Reuse exact owner contract; no automatic Sales transfer |
| `estimating.internal_cost.view` | Estimating | Actual cost operand read | Existing Estimating scope | Cost/margin only where permitted | E | Reuse exact owner contract; no automatic Sales transfer |
| `inventory.valuation.view` | Inventory | Actual stock valuation read | Existing Inventory warehouse/scope | Valuation only | E | Reuse exact owner contract; no automatic Sales transfer |
| `approvals.authority.manage` | Approvals | Authority ceilings maintenance | Existing authority guard | Limits management | E | Reuse exact owner contract; no automatic Sales transfer |
| `approvals.authority.view` | Approvals | Authority ceilings read | Existing authority guard | Limits read | E | Reuse exact owner contract; no automatic Sales transfer |
| `approvals.reason.view` | Approvals | Independent protected reason read | Existing reason/evidence contract | Reasons under0087 | E | Reuse exact owner contract; no automatic Sales transfer |
| `approvals.policy.manage` | Approvals | Version/configure shared subject review policies | Existing Approvals scope/subject contract | Policy configuration, no business transition/file read | E | Reuse0070; actual new Sales subject adapter required |
| `approvals.settings.manage` | Approvals | NONE/POLICY/REQUIRED subject settings | Existing Approvals company/subject contract | Config only | E | Reuse0070; no authority to issue |
| `approvals.delegation.manage` | Approvals | Dated principal delegation | Existing Approvals scope and anti-escalation | Delegation evidence; no additive ceilings | E | Reuse exact principal/current eligibility |
| `approvals.history.view` | Approvals | Safe request/decision history | Existing subject/history scope | Limits/reasons still independent0087 | E | Reuse existing evidence profiles |
| `company.history.view` | Company | Company brand/profile change history | Existing Company history contract | Company change evidence only | E | Reuse0081; no artefact/file view |
| `numbering.series.select` | Numbering | Explicit permitted official series choice | Existing same-company numbering contract | Series identity; no counter bypass | E | QL3 owning numbering integration |
| `approvals.document.technical-review` | Approvals | Existing PO technical review | Existing PO review subject | Request-specific operational | E | Reuse exact owner contract; no automatic Sales transfer |
| `sales.pricing.exception.approve` | Sales | Future control-specific risk exception | Not enabled | No runtime projection | D-S | Separate approved/delivered contract first |
| `sales.pricing.agreement.depart` | Sales | Future accepted-contract departure | Not enabled | No runtime projection | D-S | Separate approved/delivered contract first |
| `sales.customer-decision.claim-release` | Sales | Future commercial exclusive-claim release | Not enabled | No runtime projection | D-S | Separate approved/delivered contract first |

## Projection and registration reconciliation

Issue/prepare may expose the minimum exact chosen commercial workflow for the authorised record;
they do not imply broad `commercial.view` or confidential competing terms. Sensitive accept/late/
correct requires independent legitimate decision-read eligibility as well as its own duty/scope.
Attribution retains existing SalesAccess::mayRead semantics unchanged; no new pipeline-view gate
forced on existing qualifying CRM operators. Each duty's scope is independently effective; another
unrestricted duty cannot rescue a limited sensitive operation. Shared config never opens files.

`sales.quotation.review-operational` is the consuming Sales subject's technical permission passed to
existing ApprovalReviewRegistry::register(provider, technicalPermission); current PO uses
`approvals.document.technical-review`. Do not require both for a Sales review, rename the PO duty,
grant Sales read to PO reviewers or duplicate shared review engine. Commercial quotation and discount
subjects declare their exact own approvePermission and evidence projection through existing registry.

Inspected c25d5cb code: eight Sales strings match unchanged; new Sales/Documents strings absent from
their code vocabularies; existing Company/Currency/Estimating/Inventory/Approvals reads reused above.
FR tax.configuration.manage/tax.profile.manage candidate strings now use one owning tax registration
path (FR2 owns shared Tax provider integration; physical provider registration is implementation mechanics); no parallel Sales
tax permission. Stage code still rechecks all strings and required scopes immediately before coding
registration; a demonstrated mismatch is reconciled under existing authority, not guessed aliasing.
Broader legitimate existing source fields may have maintenance-based read exceptions: preserve actual
owning source policy, do not replace it with this matrix's example read name as universal gate.

PASS/WARN/FAIL from blind policy evaluation reveals no protected operand/threshold/candidate/recipe
or existence of confidential competitors. Omission is not zero. Apply field policy to API nested
includes, counts/filters/sorts/history/exports/print/error/cache and every worker payload. Approval
reason/ceiling access stays0087. Full original PDF requires access to its entire payload; otherwise
permitted separately identified profile/rendition or refusal, never falsely labelled redacted original.

## Related

- [0138](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md)
- [Authority behaviour](sales-authority.md) · [Rollout](../phases/phase-4-r9-permission-rollout.md)
- [R9 slices](../phases/phase-4-r9-implementation-slices.md) · [Documents](documents.md)
