<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceEligibility;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceIntent;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceReviewToken;
use Illuminate\Support\Facades\DB;

/** Exact full-scope terms and an opaque ten-minute review. Read-only: creates no policy, claim, acceptance or receipt. STAGED sensitive duty plus independent case/quotation view. */
final class PreviewCustomerAcceptance
{
    public function __construct(private readonly AcceptanceEligibility $eligibility, private readonly AcceptanceReviewToken $tokens) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId, int $componentId, int $evidenceId, int $lineId, int $caseGeneration, int $scopeGeneration = 0, ?int $choiceGeneration = null): array
    {
        AcceptanceEligibility::early($actor);
        $intent = AcceptanceIntent::of($revisionId, $componentId, $evidenceId, $lineId, $caseGeneration, $scopeGeneration, $choiceGeneration);

        return DB::transaction(function () use ($actor, $intent): array {
            $s = $this->eligibility->lock($actor, $intent);
            $this->eligibility->require($s, $intent);

            return ['intent' => $intent, 'terms' => ['issued_line_id' => $s->line->id, 'description' => $s->line->description, 'quantity' => $s->line->offered_quantity,
                'uom_id' => $s->line->offered_uom_id, 'unit_price' => $s->line->unit_price, 'currency_id' => $s->line->price_currency_id,
                'net' => $s->line->net, 'tax' => $s->line->tax, 'gross' => $s->line->gross],
                'unchanged_terms_statement' => 'The customer agrees to this full scope on the exact issued terms, unchanged.',
                'preview_token' => $this->tokens->issue($actor, AcceptanceIntent::digest($intent)), 'expires_in_seconds' => AcceptanceReviewToken::TTL_SECONDS];
        });
    }
}
