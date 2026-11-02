<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

use App\Modules\Sales\Support\PermissionVocabulary;

/**
 * Every duty the accepted R9 matrix gives to the Sales module, in matrix order ([sales-duty-matrix.md](../../../../../../docs/domain/sales-duty-matrix.md)).
 * The matrix's rows owned by other modules (Documents, Tax, Company, Currency, Estimating, Inventory, Approvals, Numbering) are deliberately **not**
 * here: one permission has one owning module, and each of those is registered, or will be, by its own.
 *
 * Only the eight existing CRM duties are ACTIVE. Four duties are STAGED: `sales.financial.configure`, `sales.quotation.view`, `sales.quotation.prepare` and `sales.document.generate` have
 * guarded Actions that exist but are disabled until their holder recipes are reviewed. Every other duty is a name with a specified meaning and no consumer: it is not registered, has no
 * constant, and is refused by `SalesAccess` until the slice that delivers its operation changes its status here, in the same change as its Action,
 * its guard, its projection and its owner-reviewed holder recipe.
 */
final class SalesDutyCatalogue
{
    /** @return list<SalesDuty> */
    public static function all(): array
    {
        return [
            new SalesDuty(PermissionVocabulary::PIPELINE_VIEW, DutyClass::Existing, DutyStatus::Active, 'Pipeline list/detail/note/history', 'CRM-safe', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::LEAD_MANAGE, DutyClass::Existing, DutyStatus::Active, 'Record/qualify/disqualify/link/convert Lead', 'CRM-safe', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::OPPORTUNITY_MANAGE, DutyClass::Existing, DutyStatus::Active, 'Create/update/stage/note/next action', 'CRM-safe', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::OPPORTUNITY_ASSIGN, DutyClass::Existing, DutyStatus::Active, 'Assign/reassign eligible active member', 'CRM-safe', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::OPPORTUNITY_CLOSE, DutyClass::Existing, DutyStatus::Active, 'Lost/abandoned/reopen; future accepted WON under0107', 'CRM-safe', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::STAGE_MANAGE, DutyClass::Existing, DutyStatus::Active, 'Configure open stages/history', 'Config', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::NUMBERING_MANAGE, DutyClass::Existing, DutyStatus::Active, 'Existing Sales numbering formats', 'Config', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::OPPORTUNITY_ATTRIBUTION_CORRECT, DutyClass::ExistingSensitive, DutyStatus::Active, 'Append historical attribution correction + independent CRM read', 'Attribution', 'Existing CRM only; future WON waits authoritative CA/RP'),
            new SalesDuty(PermissionVocabulary::QUOTATION_VIEW, DutyClass::NewOrdinary, DutyStatus::Staged, 'Quotation list/detail safe metadata', 'Quote-min', 'QL1', DutyScope::QuotationFamily),
            new SalesDuty('sales.quotation.commercial.view', DutyClass::Sensitive, DutyStatus::Specified, 'General chosen-commercial figures', 'Commercial', 'QL1/FR5'),
            new SalesDuty(PermissionVocabulary::QUOTATION_PREPARE, DutyClass::NewOrdinary, DutyStatus::Staged, 'Draft/revise/cancel-draft/preview/ordinary submit', 'Commercial-workflow', 'QL1/2 + PR/FR', DutyScope::QuotationFamily),
            new SalesDuty(PermissionVocabulary::QUOTATION_ASSIGN, DutyClass::Sensitive, DutyStatus::Staged, 'Reassign quotation owner / change access unit / internal baseline capture (independent view)', 'Ownership', 'QA1a; scoped policy QA1b', DutyScope::QuotationFamily),
            new SalesDuty('sales.quotation.issue', DutyClass::NewOrdinary, DutyStatus::Specified, 'Official issue', 'Commercial-workflow', 'QL3'),
            new SalesDuty('sales.quotation.withdraw', DutyClass::Sensitive, DutyStatus::Specified, 'Eligible unaccepted withdrawal/replacement disposition', 'Commercial-workflow', 'QL4/5; partial remains gated'),
            new SalesDuty('sales.quotation.history.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Quote lifecycle history', 'Quote-min + independent fields', 'QL1–6'),
            new SalesDuty('sales.quotation.approve', DutyClass::Sensitive, DutyStatus::Specified, 'Exact commercial review subject', 'Review-commercial', 'QL2 + shared Approvals'),
            new SalesDuty('sales.quotation.review-operational', DutyClass::Sensitive, DutyStatus::Specified, 'Exact operational review profile', 'Review-operational', 'QL2 + review adapter'),
            new SalesDuty('sales.quotation.discount', DutyClass::NewOrdinary, DutyStatus::Specified, 'Routine specific discretionary input', 'Commercial-workflow', 'PR4/5 + AU3 limits'),
            new SalesDuty('sales.quotation.price.override', DutyClass::Sensitive, DutyStatus::Specified, 'Reasoned final net override', 'Commercial-workflow', 'PR4/5 + AU3; no risk bypass'),
            new SalesDuty('sales.quotation.discount.approve', DutyClass::Sensitive, DutyStatus::Specified, 'Exact above-limit discount subject', 'Review-commercial', 'AU4 + supported policy'),
            new SalesDuty('sales.quotation.associate', DutyClass::Sensitive, DutyStatus::Specified, 'Current CRM association, original0099 preserved', 'CRM + quote association', 'QL association Action + both source/destination eligibility'),
            new SalesDuty('sales.quotation.policy.manage', DutyClass::Sensitive, DutyStatus::Specified, 'Validity/unknown-payment configuration', 'Config', 'QL0/4/6'),
            new SalesDuty('sales.pricing.configure', DutyClass::Sensitive, DutyStatus::Specified, 'Version supported base/rule/plan/control policy', 'Config writable terms', 'PR1–4/6'),
            new SalesDuty('sales.pricing.eligibility.manage', DutyClass::Sensitive, DutyStatus::Specified, 'Dated Party pricing memberships', 'Party eligibility', 'PR2'),
            new SalesDuty('sales.pricing.agreement.manage', DutyClass::Sensitive, DutyStatus::Specified, 'Contractual pricing versions', 'Confidential terms', 'PR2/3; cannot depart accepted terms'),
            new SalesDuty('sales.pricing.terms.view', DutyClass::Sensitive, DutyStatus::Specified, 'Read otherwise protected term choices', 'Confidential terms', 'PR2/3'),
            new SalesDuty(PermissionVocabulary::FINANCIAL_CONFIGURE, DutyClass::Sensitive, DutyStatus::Staged, 'Sales FX/rounding policy', 'Config', 'FR1/4'),
            new SalesDuty('sales.customer-decision.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Decision/case safe detail/history', 'Decision-min', 'CA1'),
            new SalesDuty('sales.customer-decision.evidence.record', DutyClass::NewOrdinary, DutyStatus::Specified, 'Shared intake/append observations', 'Evidence-write', 'CA1; file requires secure DF'),
            new SalesDuty('sales.customer-decision.accept', DutyClass::Sensitive, DutyStatus::Specified, 'Authoritative ordinary consent/claims', 'Exact consent', 'CA2/3'),
            new SalesDuty('sales.customer-decision.reconcile', DutyClass::Sensitive, DutyStatus::Specified, 'Late recognition', 'Historical consent/impact', 'CA5 + affected HA'),
            new SalesDuty('sales.customer-decision.correct', DutyClass::Sensitive, DutyStatus::Specified, 'Governed accepted reduction', 'Exact consent/impact', 'HA6/CA5'),
            new SalesDuty('sales.customer-decision.evidence.view', DutyClass::Sensitive, DutyStatus::Specified, 'Confidential evidence/files', 'Evidence-read', 'CA1 + file adapter'),
            new SalesDuty('sales.handover.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Safe plan/readiness/target links', 'Operational', 'HA1'),
            new SalesDuty('sales.handover.prepare', DutyClass::NewOrdinary, DutyStatus::Specified, 'Prepare plan, no target/stock', 'Operational', 'HA1'),
            new SalesDuty('sales.handover.release', DutyClass::Sensitive, DutyStatus::Specified, 'Release exact supported component', 'Operational + baseline', 'HA2 + real target'),
            new SalesDuty('sales.handover.hold', DutyClass::Sensitive, DutyStatus::Specified, 'Authorised operational hold', 'Operational', 'HA5'),
            new SalesDuty('sales.handover.amend', DutyClass::Sensitive, DutyStatus::Specified, 'Schedule/unused cancellation/reassignment', 'Operational/impact', 'HA4/5'),
            new SalesDuty('sales.document.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Safe generation/artefact history/status', 'Document-min', 'DF2/DOC1'),
            new SalesDuty(PermissionVocabulary::DOCUMENT_GENERATE, DutyClass::NewOrdinary, DutyStatus::Staged, 'Request pinned generation/retry', 'Customer render profile', 'DF3/DOC2'),
            new SalesDuty('sales.document.repair', DutyClass::Sensitive, DutyStatus::Specified, 'Authorise labelled semantic rendition', 'Customer render profile', 'DF4/DOC3'),
            new SalesDuty('sales.document.download', DutyClass::NewOrdinary, DutyStatus::Specified, 'Original/permitted rendition/print invocation', 'Exact whole payload', 'DF4/DOC4'),
            new SalesDuty('sales.document.attachment.manage', DutyClass::Sensitive, DutyStatus::Specified, 'Validate/link allowed evidence attachment', 'Association write', 'DF4 + CA/DOC'),
            new SalesDuty('sales.document.attachment.view', DutyClass::Sensitive, DutyStatus::Specified, 'Independent confidential file access', 'Exact association', 'DF4 + owner adapter'),
            new SalesDuty('sales.delivery.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Safe recipient/attempt history', 'Recipient/attempt; confidential proof independently', 'DOC4'),
            new SalesDuty('sales.delivery.record', DutyClass::NewOrdinary, DutyStatus::Specified, 'Manual actual-channel evidence', 'Recipient/attempt; confidential proof independently', 'DOC4'),
            new SalesDuty('sales.delivery.send', DutyClass::DormantSensitive, DutyStatus::Dormant, 'External current/historical dispatch', 'Recipient/attempt; confidential proof independently', 'DOC6 supported provider only'),
            new SalesDuty('sales.delivery.reconcile', DutyClass::DormantSensitive, DutyStatus::Dormant, 'Provider unknown-result disposition', 'Recipient/attempt; confidential proof independently', 'DOC6 durable protocol'),
            new SalesDuty('sales.forecast.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Forecast basis/trust/source view', 'Forecast commercial; no hidden sources/FX-admin', 'RP1 safe basis; RP4 for published forecast; CA/QL sources'),
            new SalesDuty('sales.forecast.maintain', DutyClass::NewOrdinary, DutyStatus::Specified, 'Maintain explicit remaining assumption', 'Forecast commercial; no hidden sources/FX-admin', 'RP1 + actual Opportunity; acceptance effects need CA/QL integration'),
            new SalesDuty('sales.forecast.select', DutyClass::NewOrdinary, DutyStatus::Specified, 'Choose eligible noncompeting issued set', 'Forecast commercial; no hidden sources/FX-admin', 'RP1 + actual eligible QL/CA remaining-value adapters'),
            new SalesDuty('sales.forecast.review', DutyClass::Sensitive, DutyStatus::Specified, 'Explicit commercial trust review', 'Forecast commercial; no hidden sources/FX-admin', 'RP3 + exact basis/source review'),
            new SalesDuty('sales.forecast.publish', DutyClass::Sensitive, DutyStatus::Specified, 'Publish reviewed immutable version', 'Forecast commercial; no hidden sources/FX-admin', 'RP4 + all source writer invalidation'),
            new SalesDuty('sales.forecast.value', DutyClass::Sensitive, DutyStatus::Specified, 'Explicit dated management valuation', 'Forecast commercial; no hidden sources/FX-admin', 'RP2 + actual FR/Currency primitives'),
            new SalesDuty('sales.report.view', DutyClass::NewOrdinary, DutyStatus::Specified, 'Scoped measures/known/effective-as-of reports', 'Report-safe; no hidden totals', 'RP0 for implemented accepted measures; RP5 for history/forecast'),
            new SalesDuty('sales.report.export', DutyClass::NewOrdinary, DutyStatus::Specified, 'Same permitted report projection exported', 'Report-safe; no hidden totals', 'Same delivered report as read + AU export guard; RP5 history/forecast'),
            new SalesDuty('sales.pricing.exception.approve', DutyClass::DormantSensitive, DutyStatus::Dormant, 'Future control-specific risk exception', 'No runtime projection', 'Separate approved/delivered contract first'),
            new SalesDuty('sales.pricing.agreement.depart', DutyClass::DormantSensitive, DutyStatus::Dormant, 'Future accepted-contract departure', 'No runtime projection', 'Separate approved/delivered contract first'),
            new SalesDuty('sales.customer-decision.claim-release', DutyClass::DormantSensitive, DutyStatus::Dormant, 'Future commercial exclusive-claim release', 'No runtime projection', 'Separate approved/delivered contract first'),
        ];
    }
}
