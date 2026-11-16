<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Resources;

use App\Modules\Jobs\Queries\JobReadProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobReadProjection */
final class JobDetailResource extends JsonResource
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
            /** @var array{version:int,scope_description:string,deliverable:string|null,agreed_conditions:string|null,recorded_at:string|null}|null */
            'baseline' => $fields['baseline'],
            /** @var array{member_id:int,member_name:string|null,intended_from:string|null,intended_until:string|null,recorded_at:string|null}|null */
            'assignment' => $fields['assignment'],
            /** @var list<array{id:int,reason:string,effective_from:string|null,recorded_at:string|null}> */
            'open_holds' => $fields['open_holds'],
            /** @var array{id:int,description:string}|null */
            'default_activity' => $fields['default_activity'],
            /** @var array{statement:string|null,recorded_at:string|null,claimed_occurred_at:string|null,occurrence_precision:string}|null */
            'completion' => $fields['completion'],
            /** @var array{statement:string|null,recorded_at:string|null,claimed_occurred_at:string|null,occurrence_precision:string}|null */
            'closeout' => $fields['closeout'],
            /** @var array{family_id:int,revision_id:int} */
            'sales_origin' => $this->when(array_key_exists('sales_origin', $fields), fn () => $fields['sales_origin']),
        ];
    }
}
