<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Queries\JobReadProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobReadProjection */
final class JobHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JobReadProjection $projection */
        $projection = $this->resource;
        $fields = $projection->toArray();

        return [
            /** @var int */
            'id' => $fields['id'],
            /** @var int */
            'sequence' => $fields['sequence'],
            /** @var string */
            'operation' => $fields['operation'],
            /** @var string|null */
            'from_state' => $fields['from_state'],
            /** @var string */
            'to_state' => $fields['to_state'],
            /** @var string|null */
            'recorded_at' => $fields['recorded_at'],
            /** @var string|null */
            'claimed_occurred_at' => $fields['claimed_occurred_at'],
            /** @var string */
            'occurrence_precision' => $fields['occurrence_precision'],
            /** @var array{id:int,name:string|null} */
            'actor' => $fields['actor'],
            /** @var string|null */
            'reason' => $fields['reason'],
            /** @var int|null */
            'responsible_member_id' => $fields['responsible_member_id'],
            /** @var int|null */
            'hold_id' => $fields['hold_id'],
        ];
    }
}
