<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Support\Authority\SalesDutyManifest;

/**
 * Sales capabilities, named by duty (authorization.md R14). Owning a record is responsibility, not authority (ADR 0106): none of these is implied by being
 * an owner, a manager or a role name, and none grants any Finance or credit right. Nothing is granted by inference at rollout; administrators assign them.
 */
final class PermissionVocabulary
{
    /** Read the pipeline: leads, opportunities, notes and history. */
    public const PIPELINE_VIEW = 'sales.pipeline.view';

    /** Record, qualify, disqualify and convert enquiries. */
    public const LEAD_MANAGE = 'sales.lead.manage';

    /** Create and maintain opportunities, move their stage, keep notes and the next action. */
    public const OPPORTUNITY_MANAGE = 'sales.opportunity.manage';

    /** Change who is accountable for an opportunity or an enquiry. */
    public const OPPORTUNITY_ASSIGN = 'sales.opportunity.assign';

    /** Close an opportunity as lost or abandoned, and reopen a revived pursuit. */
    public const OPPORTUNITY_CLOSE = 'sales.opportunity.close';

    /** Configure the OPEN stages. */
    public const STAGE_MANAGE = 'sales.stage.manage';

    /** Number formats for leads and opportunities. */
    public const NUMBERING_MANAGE = 'sales.numbering.manage';

    /**
     * Correct who the record says was responsible, on a closed deal, without reopening it (ADR 0112/0114). Retrospective attribution can change
     * historical accountability while every current fact stays as it is, so it is a sensitive duty of its own: assigning, closing, editing, owning the
     * deal or holding a manager title does not imply it, and no automatic all-capabilities grant includes it.
     */
    public const OPPORTUNITY_ATTRIBUTION_CORRECT = 'sales.opportunity.attribution.correct';

    /**
     * Sales financial policy: unit-price entry precision and the settlement rounding rule (ADR 0144). STAGED, not registered: its guarded Actions exist
     * but `SalesAccess` refuses every caller until the manifest activates it. It is therefore deliberately absent from `all()`.
     */
    public const FINANCIAL_CONFIGURE = 'sales.financial.configure';

    /** Independent activation of a proposed governed reference; STAGED, no grants. */
    public const AUTHORITY_EVIDENCE_VIEW = 'sales.discount-authority.evidence.view';

    public const PRICE_REFERENCE_APPROVE = 'sales.pricing.reference.approve';

    /**
     * Quotation draft preparation (QL1a). STAGED like FINANCIAL_CONFIGURE: `ReadQuotationDraft` (view) and `CreateQuotationDraft`, `UpdateQuotationDraft`,
     * `PreviewQuotation` (prepare) exist and are guarded, but `SalesAccess` refuses every caller until the manifest activates them with a reviewed holder recipe.
     * Neither is in `all()`, so nothing registers or grants them.
     */
    public const QUOTATION_VIEW = 'sales.quotation.view';

    public const QUOTATION_PREPARE = 'sales.quotation.prepare';

    /**
     * Quotation ownership (QA1a). STAGED like the other quotation duties: `ReassignQuotationOwner`, `ChangeQuotationAccessUnit` and the internal
     * `CaptureQuotationAccessBaseline` exist and are guarded, but `SalesAccess` refuses every caller until the manifest activates it with a reviewed holder recipe.
     * It is sensitive (it changes who is accountable), never part of an automatic grant, and absent from `all()`.
     */
    public const QUOTATION_ASSIGN = 'sales.quotation.assign';

    /** Sensitive, STAGED family access management; never implied by receiving a share. */
    public const QUOTATION_ACCESS_DELEGATE = 'sales.quotation.access.delegate';

    public const QUOTATION_ACCESS_REVOKE = 'sales.quotation.access.revoke';

    /**
     * Official issue of a quotation revision (QL3, ADR 0123). STAGED like the other quotation duties: `IssueQuotation` exists and is guarded, but `SalesAccess` refuses every caller
     * until the manifest activates it with a reviewed holder recipe. It is the checker half of the routine maker/checker split — a smaller group than the preparers, never implied by
     * `sales.quotation.prepare` — and is not in `all()`, so nothing registers or grants it.
     */
    public const QUOTATION_ISSUE = 'sales.quotation.issue';

    /**
     * Withdrawal of an issued, unaccepted quotation, and its whole replacement by a later revision (QL4/QL5). STAGED: `WithdrawQuotationOffer` and `IssueQuotationReplacement` exist
     * and are guarded, but `SalesAccess` refuses every caller until the manifest activates it. Sensitive; not in `all()`.
     */
    public const QUOTATION_WITHDRAW = 'sales.quotation.withdraw';

    /**
     * Recording what a customer sent (CA1): opening a shared intake case and appending textual observations to it. STAGED: `OpenCustomerDecisionCase` and
     * `AppendCustomerDecisionEvidence` exist and are guarded, but `SalesAccess` refuses every caller until the manifest activates it. An observation is never consent.
     */
    public const CUSTOMER_DECISION_EVIDENCE_RECORD = 'sales.customer-decision.evidence.record';

    /** Prepare one immutable pending service plan; STAGED, no registration, grant, release or Job creation. */
    public const HANDOVER_PREPARE = 'sales.handover.prepare';

    /** Record full ordinary consent; STAGED, independent decision/source read still required. */
    public const CUSTOMER_DECISION_ACCEPT = 'sales.customer-decision.accept';

    /**
     * Reading what was recorded for a customer (CA1 slice 1): the case list, the case with its components and the observation chain. STAGED exactly like the record duty: the read
     * Actions and routes exist and are guarded, but `SalesAccess` refuses every caller until the manifest activates it. It is an independent right: recording does not imply
     * reading, and reading never implies recording. Not in `all()`.
     */
    public const CUSTOMER_DECISION_VIEW = 'sales.customer-decision.view';

    /**
     * Quotation document generation (DOC0 skeleton). STAGED: `BindQuotationDocument` and the quotation document adapter's access callback ask for it, but
     * `SalesAccess` refuses every caller until the manifest activates it. Not in `all()`.
     */
    public const DOCUMENT_GENERATE = 'sales.document.generate';

    /**
     * The rest of the quotation document feature (DF3/DF4 + DOC2-DOC4). All STAGED: the guarded Actions exist, nobody holds them until a holder recipe is reviewed.
     * `view` reads status and history, `download` hands the stored original out, `repair` is the operator recovery, `delivery.view|record` read and write the manual
     * delivery evidence. None of them is customer receipt, consent or acceptance.
     */
    public const DOCUMENT_VIEW = 'sales.document.view';

    public const DOCUMENT_DOWNLOAD = 'sales.document.download';

    public const DOCUMENT_REPAIR = 'sales.document.repair';

    public const DELIVERY_VIEW = 'sales.delivery.view';

    public const DELIVERY_RECORD = 'sales.delivery.record';

    /**
     * Pricing concessions on a draft (PR4/PR5, ADR 0116). STAGED like the other quotation duties: the guarded draft Actions ask for them, `SalesAccess`/`QuotationAccess` refuse every
     * caller until a reviewed holder recipe activates them. `discount` is the routine specific discount (line or document); `price.override` is the sensitive reasoned final-net
     * override; `commercial.view` is the independent right to see chosen commercial figures (recipient name and net amount) in the quotation list. None implies another, and none
     * is part of an automatic grant or of `all()`.
     */
    public const QUOTATION_DISCOUNT = 'sales.quotation.discount';

    public const QUOTATION_PRICE_OVERRIDE = 'sales.quotation.price.override';

    public const QUOTATION_COMMERCIAL_VIEW = 'sales.quotation.commercial.view';

    /**
     * Approving or rejecting a draft's recorded need for approval (ADR 0158). STAGED like the other quotation duties: `DecideQuotationApproval` and the Approvals engine's eligibility ask for
     * it, but `SalesAccess`/`QuotationAccess` refuse every caller until the manifest activates it with an owner-reviewed holder recipe. Sensitive, judged by the quotation-family
     * record policy (OWN or ALL) with the independent view; never implied by prepare, discount, override or issue, and the preparer of a draft can never approve it. Not in `all()`.
     */
    public const QUOTATION_APPROVE = 'sales.quotation.approve';

    /**
     * The registered Sales duties: the manifest's ACTIVE ones, today exactly the eight above. Every other Sales duty in the accepted matrix is specified
     * in `Authority\SalesDutyManifest` and deliberately has no constant here until the slice that delivers it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return SalesDutyManifest::shipped()->activeNames();
    }
}
