<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Queries\JobReadProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobReadProjection */
final class JobSummaryResource extends JsonResource
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
            /** @var string */
            'number' => $fields['number'],
            /** @var string */
            'state' => $fields['state'],
            /** @var string|null */
            'description' => $fields['description'],
            /** @var array{id:int,name:string|null}|null */
            'responsible_member' => $fields['responsible_member'],
            /** @var bool */
            'has_open_hold' => $fields['has_open_hold'],
            /** @var string|null */
            'created_at' => $fields['created_at'],
            /** @var string|null */
            'released_at' => $fields['released_at'],
            /** @var string|null */
            'started_at' => $fields['started_at'],
        ];
    }
}
