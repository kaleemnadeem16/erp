<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SalesStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesStage */
class SalesStageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SalesStage $stage */
        $stage = $this->resource;

        return ['id' => $stage->id, 'code' => $stage->code, 'name' => $stage->name, 'sort_order' => $stage->sort_order, 'status' => $stage->status];
    }
}
