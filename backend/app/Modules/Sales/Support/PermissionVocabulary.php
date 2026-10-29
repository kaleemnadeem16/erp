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

    /**
     * Official issue of a quotation revision (QL3, ADR 0123). STAGED like the other quotation duties: `IssueQuotation` exists and is guarded, but `SalesAccess` refuses every caller
     * until the manifest activates it with a reviewed holder recipe. It is the checker half of the routine maker/checker split — a smaller group than the preparers, never implied by
     * `sales.quotation.prepare` — and is not in `all()`, so nothing registers or grants it.
     */
    public const QUOTATION_ISSUE = 'sales.quotation.issue';

    /**
     * Quotation document generation (DOC0 skeleton). STAGED: `BindQuotationDocument` and the quotation document adapter's access callback ask for it, but
     * `SalesAccess` refuses every caller until the manifest activates it. Not in `all()`.
     */
    public const DOCUMENT_GENERATE = 'sales.document.generate';

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
