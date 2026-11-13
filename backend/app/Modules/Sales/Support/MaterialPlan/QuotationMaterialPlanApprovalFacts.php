<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Estimating\Support\MaterialPlanApprovalFacts;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationMaterialPlanApproval;
use App\Modules\Sales\Models\QuotationRevision;

/**
 * What an adopted plan tells the approval engine (ADR 0054 §7, ADR 0076, ADR 0122): the facts frozen with the adoption — the material, its category and the internal-cost figure — plus
 * the plan's identity, so an approval can only ever be for exactly the adoption it was requested on. Nothing is read live: a later cost or policy change cannot move a request's facts.
 */
final class QuotationMaterialPlanApprovalFacts implements MaterialPlanApprovalFacts
{
    public function routing(string $subjectCode, int $subjectId): ApprovalRouting
    {
        $row = QuotationMaterialPlanApproval::query()->where('subject_code', $subjectCode)->findOrFail($subjectId);
        $plan = QuotationMaterialPlan::query()->findOrFail($row->plan_id);
        $revision = QuotationRevision::query()->findOrFail($plan->revision_id);
        $facts = $row->facts;
        $amount = $facts['amount_base'] ?? null;

        return new ApprovalRouting(
            array_filter($facts, fn ($v): bool => $v !== null) + ['plan_id' => $plan->id, 'plan_fingerprint' => $plan->routing_fingerprint],
            $amount === null ? null : (string) $amount,
            ['fingerprint_version' => EvaluateMaterialPlan::VERSION],
            ['number' => "Quotation draft r{$revision->revision_ordinal} (#{$revision->id}), line #{$plan->line_id}", 'title' => $subjectCode === 'STOCK_MIX' ? 'Hand-chosen stock' : 'Material plan variance'],
        );
    }
}
