<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationDiscountAuthorityResource extends JsonResource
{
    /** @return array{revision_id:int, mode:string, current:array{lines:list<array{line_id:int,reference_id:int,reference_unit_price:string,reference_amount:string,final_net_amount:string,reduction_amount:string,reduction_base_amount:string,document_allocation:string,fx_fingerprint:string|null}>,result:string,reasons:list<string>,fingerprint:string,policy_id:int|null,required_rank:int|null,required_band_id:int|null,currency_id:int|null,base_currency_id:int|null,reference_amount:string|null,reduction_amount:string|null,reduction_base_amount:string|null,final_net_amount:string|null,document_percent_exact:string|null}|null, records:list<array{id:int,actor_id:int,mode:string,input_generation:int,recorded_at:string,evaluation:array<string,mixed>}>,page:int,per_page:int,total:int} */
    public function toArray(Request $request): array
    {
        return ['revision_id' => $this->resource['revision_id'], 'mode' => $this->resource['mode'], 'current' => $this->resource['current'],
            'records' => $this->resource['records'], 'page' => $this->resource['page'], 'per_page' => $this->resource['per_page'], 'total' => $this->resource['total']];
    }
}
