<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Support\Facades\DB;

/** Safe immutable acceptance projection under today's independent decision/quotation read. No cost, margin, FX fingerprints or competing claim identities. */
final class ReadCustomerAcceptance
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $acceptanceId): array
    {
        CustomerDecisionAccess::early($actor, P::CUSTOMER_DECISION_VIEW, 'see customer acceptance');

        return DB::transaction(function () use ($actor, $acceptanceId): array {
            $fresh = CustomerDecisionAccess::decide($actor, P::CUSTOMER_DECISION_VIEW, 'see customer acceptance');
            $a = CustomerAcceptance::query()->find($acceptanceId)
                ?? throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::ACCEPTANCE_NOT_FOUND, 'The acceptance was not found.');
            app(QuotationAccess::class)->requireRevision($fresh, $a->revision_id, [P::QUOTATION_VIEW], false, false);
            $lineId = CustomerAcceptanceLine::query()->where('acceptance_id', $a->id)->value('issued_line_id');
            $line = QuotationIssuedLine::query()->findOrFail($lineId);

            return ['id' => $a->id, 'case_id' => $a->case_id, 'decision_component_id' => $a->decision_component_id, 'revision_id' => $a->revision_id,
                'consent_evidence_id' => $a->consent_evidence_id, 'policy_version_id' => $a->policy_version_id, 'recorded_by' => $a->actor_id, 'recorded_at' => $a->recorded_at->toIso8601String(),
                'scope' => ['issued_line_id' => $line->id, 'logical_scope_id' => $line->logical_scope_id, 'choice_group_id' => $line->choice_group_id,
                    'description' => $line->description, 'source_kind' => $line->source_kind, 'quantity' => $line->offered_quantity, 'uom_id' => $line->offered_uom_id,
                    'unit_price' => $line->unit_price, 'currency_id' => $line->price_currency_id, 'net' => $line->net, 'tax' => $line->tax, 'gross' => $line->gross],
                'acceptance_policy' => 'FULL_ONLY', 'unchanged_terms' => true];
        });
    }
}
