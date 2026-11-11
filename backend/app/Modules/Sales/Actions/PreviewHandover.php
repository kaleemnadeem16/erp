<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceReviewToken;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverInput;
use App\Modules\Sales\Support\Handover\ServiceHandoverAssessment;
use Illuminate\Support\Facades\DB;

/** Read-only pending-service review; encrypted actor-bound token, no capacity or receipt. STAGED prepare plus independent acceptance/quotation read. */
final class PreviewHandover
{
    public function __construct(private readonly HandoverAccess $access, private readonly ServiceHandoverAssessment $readiness, private readonly AcceptanceReviewToken $tokens) {}

    /**
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    public function __invoke(User $actor, int $acceptanceId, array $fields = []): array
    {
        HandoverAccess::early($actor, true);
        $inputs = HandoverInput::normalise($fields);

        return DB::transaction(function () use ($actor, $acceptanceId, $inputs): array {
            $s = $this->access->lock($actor, $acceptanceId);
            $state = $this->readiness->assess($s, $inputs, true);

            return ['acceptance_id' => $acceptanceId, 'inputs' => $inputs, 'operational' => $state['operational'], 'readiness' => $state['assessment'],
                'withheld' => ['commercial_prices', 'commercial_quantities', 'tax_fx_evidence', 'costs_and_margins'],
                'preview_token' => $this->tokens->issue($actor, $state['fingerprint']), 'expires_in_seconds' => AcceptanceReviewToken::TTL_SECONDS];
        });
    }
}
