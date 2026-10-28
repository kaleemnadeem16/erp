<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What a draft write reports and nothing more: where the draft is and the input generation it reached. No price, cost or other figure, and a replay carries the same stored ids. */
class QuotationDraftWriteResource extends JsonResource
{
    /** @return array{family_id: int, revision_id: int, input_generation: int, replayed: bool} */
    public function toArray(Request $request): array
    {
        /** @var QuotationDraftOutcome $outcome */
        $outcome = $this->resource;

        return ['family_id' => (int) $outcome->familyId, 'revision_id' => (int) $outcome->revisionId, 'input_generation' => (int) $outcome->inputGeneration, 'replayed' => (bool) $outcome->replayed];
    }
}
