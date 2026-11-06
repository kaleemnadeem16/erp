<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Support\Quotation\QuotationShareOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuotationShareOutcomeResource extends JsonResource
{
    /** @return array{family_id: int, grant_id: int|null, ownership_generation: int, access_generation: int, changed: bool, replayed: bool} */
    public function toArray(Request $request): array
    {
        /** @var QuotationShareOutcome $data */
        $data = $this->resource;

        return ['family_id' => $data->familyId, 'grant_id' => $data->grantId, 'ownership_generation' => $data->ownershipGeneration, 'access_generation' => $data->accessGeneration, 'changed' => $data->changed, 'replayed' => $data->replayed];
    }
}
