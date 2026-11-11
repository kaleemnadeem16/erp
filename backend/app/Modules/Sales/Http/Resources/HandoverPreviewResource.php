<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe service preparation projection; no commercial/cost evidence or authority inference. */
final class HandoverPreviewResource extends JsonResource
{
    /** @return array{acceptance_id:int,inputs:array{path:string,location_kind:string,contact_id:int|null,site_id:int|null,instructions:string},operational:array{deliverable_identity:string,description:string,measure_id:null,quantity:null,completion_conditions:null,location_kind:string,instructions:string,contact:array{id:int,name:string,email:string|null,phone:string|null}|null,site:array{id:int,name:string,address_line1:string|null,city:string|null,country_code:string|null}|null},readiness:array{acceptance_id:int,assessment_version:string,path:string,status:string,preparation_ready:bool,missing:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>},withheld:list<string>,preview_token:string,expires_in_seconds:int} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,inputs:array{path:string,location_kind:string,contact_id:int|null,site_id:int|null,instructions:string},operational:array{deliverable_identity:string,description:string,measure_id:null,quantity:null,completion_conditions:null,location_kind:string,instructions:string,contact:array{id:int,name:string,email:string|null,phone:string|null}|null,site:array{id:int,name:string,address_line1:string|null,city:string|null,country_code:string|null}|null},readiness:array{acceptance_id:int,assessment_version:string,path:string,status:string,preparation_ready:bool,missing:list<string>,next_actions:list<array{fact:string,owner:string,action:string}>},withheld:list<string>,preview_token:string,expires_in_seconds:int} $data */
        $data = $this->resource;

        return $data;
    }
}
