<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Whether a handover was prepared for one accepted service, the ids to open it and whether it was released. No Job, text or evidence. */
final class HandoverStatusResource extends JsonResource
{
    /** @return array{acceptance_id:int,prepared:bool,plan_id:int|null,stage_id:int|null,release_performed:bool} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,prepared:bool,plan_id:int|null,stage_id:int|null,release_performed:bool} $data */
        $data = $this->resource;

        return $data;
    }
}
