<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuotationFamilyAccessResource extends JsonResource
{
    /**
     * @return array{family_id:int,record_scope:'ALL'|'OWN'|'MY_UNITS'|'DELEGATED',created_by:int,created_by_name:string|null,responsible_owner:array{id:int,name:string,status:string}|null,access_unit:array{id:int,name:string,status:string}|null,baseline_state:string,ownership_generation:int,access_generation:int,allowed_actions:array{prepare:bool,assign:bool,delegate_view:bool,delegate_prepare:bool,revoke:bool},ownership_history?:list<array{id:int,generation:int,kind:string,old_owner_id:int|null,new_owner_id:int,old_unit_id:int|null,new_unit_id:int|null,actor_id:int,recorded_at:string,old_owner_name:string|null,new_owner_name:string|null,actor_name:string|null,old_unit_name:string|null,new_unit_name:string|null}>,ownership_history_has_more?:bool,ownership_history_next_generation?:int|null,shares?:list<array{id:int,granted_generation:int,recipient_id:int,recipient_name:string,issuer_id:int,issuer_name:string,revoked_by_name:string|null,level:string,starts_at:string,ends_at:string|null,recorded_at:string,revoked_by:int|null,revoked_at:string|null,status:string}>,shares_has_more?:bool,shares_next_generation?:int|null}
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        foreach (['ownership_history', 'shares'] as $collection) {
            if (! isset($data[$collection])) {
                continue;
            }
            foreach ($data[$collection] as &$row) {
                foreach (['recorded_at', 'starts_at', 'ends_at', 'revoked_at'] as $field) {
                    if (isset($row[$field])) {
                        $row[$field] = CarbonImmutable::parse($row[$field])->utc()->format('Y-m-d\TH:i:s.u\Z');
                    }
                }
            }
            unset($row);
        }

        return $data;
    }
}
