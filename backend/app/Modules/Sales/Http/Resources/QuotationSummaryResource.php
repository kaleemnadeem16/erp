<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Only the safe, already-authorised list projection. */
final class QuotationSummaryResource extends JsonResource
{
    /** @return array{revision_id:int,family_id:int,revision_ordinal:int,state:string,recipient_party_id:int|null,currency_id:int,created_at:string|null,valid_through_local_date:string|null,official_number:string|null,availability:string,responsible_owner_id:int|null,recipient_name:string|null,net_amount:string|null} */
    public function toArray(Request $request): array
    {
        /** @var array{revision_id:int,family_id:int,revision_ordinal:int,state:string,recipient_party_id:int|null,currency_id:int,created_at:string|null,valid_through_local_date:string|null,official_number:string|null,availability:string,responsible_owner_id:int|null,recipient_name:string|null,net_amount:string|null} $summary */
        $summary = $this->resource;

        return $summary;
    }
}
