<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an intake write reports: the case, its new generation (the expected head for the next change) and, for an observation, its id. Nothing about the evidence content. An
 * exact retry returns the stored result with `replayed` true.
 *
 * @property CustomerDecisionOutcome $resource
 */
final class CustomerDecisionOutcomeResource extends JsonResource
{
    /** @return array{case_id:int,generation:int,evidence_id:int|null,replayed:bool} */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return ['case_id' => $o->caseId, 'generation' => $o->generation, 'evidence_id' => $o->evidenceId, 'replayed' => $o->replayed];
    }
}
