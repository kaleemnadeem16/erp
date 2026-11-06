<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Support\Quotation\QuotationOwnershipOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuotationOwnershipOutcomeResource extends JsonResource
{
    /** @return array{family_id: int, owner_id: int, access_unit_id: int|null, ownership_generation: int, access_generation: int, changed: bool, replayed: bool} */
    public function toArray(Request $request): array
    {
        /** @var QuotationOwnershipOutcome $data */
        $data = $this->resource;

        return ['family_id' => $data->familyId, 'owner_id' => $data->ownerId, 'access_unit_id' => $data->accessUnitId, 'ownership_generation' => $data->ownershipGeneration, 'access_generation' => $data->accessGeneration, 'changed' => $data->changed, 'replayed' => $data->replayed];
    }
}
