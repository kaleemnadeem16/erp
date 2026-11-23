<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Queries\JobOperationContextProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobOperationContextProjection */
final class JobOperationContextResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JobOperationContextProjection $projection */
        $projection = $this->resource;
        $fields = $projection->fields;

        return [
            /** @var int */
            'job_id' => $fields['job_id'],
            /** @var string */
            'number' => $fields['number'],
            /** @var string */
            'state' => $fields['state'],
            /** @var int */
            'generation' => $fields['generation'],
            /** @var string */
            'operation' => $fields['operation'],
            /** @var int|null */
            'responsibility_id' => $this->when(array_key_exists('responsibility_id', $fields), fn () => $fields['responsibility_id']),
            /** @var bool */
            'has_responsibility' => $this->when(array_key_exists('has_responsibility', $fields), fn () => $fields['has_responsibility']),
            /** @var int */
            'open_hold_count' => $this->when(array_key_exists('open_hold_count', $fields), fn () => $fields['open_hold_count']),
            /** @var list<array{id:int,reason:string,recorded_at:string}> */
            'open_holds' => $this->when(array_key_exists('open_holds', $fields), fn () => $fields['open_holds']),
            /** @var string|null */
            'completion_recorded_at' => $this->when(array_key_exists('completion_recorded_at', $fields), fn () => $fields['completion_recorded_at']),
        ];
    }
}
