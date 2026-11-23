<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Review of one stage's release. The controller withholds source pins, family answers, lineage references, the policy version and any evidence text. */
final class ServiceReleasePreviewResource extends JsonResource
{
    /** @return array{ready:bool,blockers:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>,cost:array{required:bool,status:string},applicability:array{status:string,materials:string,subcontract:string},basis_id:int|null,basis_version:int|null,fingerprint:string} */
    public function toArray(Request $request): array
    {
        /** @var array{ready:bool,blockers:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>,cost:array{required:bool,status:string},applicability:array{status:string,materials:string,subcontract:string},basis_id:int|null,basis_version:int|null,fingerprint:string} $data */
        $data = $this->resource;

        return $data;
    }
}
