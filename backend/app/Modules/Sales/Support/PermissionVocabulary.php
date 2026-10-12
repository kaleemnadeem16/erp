<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

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

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PIPELINE_VIEW,
            self::LEAD_MANAGE,
            self::OPPORTUNITY_MANAGE,
            self::OPPORTUNITY_ASSIGN,
            self::OPPORTUNITY_CLOSE,
            self::STAGE_MANAGE,
            self::NUMBERING_MANAGE,
            self::OPPORTUNITY_ATTRIBUTION_CORRECT,
        ];
    }
}
