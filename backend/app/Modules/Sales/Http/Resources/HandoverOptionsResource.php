<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The accepted customer's selectable contacts and active sites for preparing one handover. Nothing of any other customer. */
final class HandoverOptionsResource extends JsonResource
{
    /** @return array{acceptance_id:int,contacts:list<array{id:int,name:string,email:string|null,phone:string|null}>,sites:list<array{id:int,name:string,address_line1:string|null,city:string|null,country_code:string|null}>} */
    public function toArray(Request $request): array
    {
        /** @var array{acceptance_id:int,contacts:list<array{id:int,name:string,email:string|null,phone:string|null}>,sites:list<array{id:int,name:string,address_line1:string|null,city:string|null,country_code:string|null}>} $data */
        $data = $this->resource;

        return $data;
    }
}
