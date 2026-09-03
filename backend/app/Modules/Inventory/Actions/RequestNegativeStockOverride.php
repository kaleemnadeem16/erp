<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMode;
use App\Modules\Inventory\Support\NegativeStockMovementClass;
use App\Modules\Inventory\Support\NegativeStockReasonCode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class RequestNegativeStockOverride
{
    /** @param array{item_id:int,location_id:int,batch_id?:int|null,movement_class:string,requested_qty:string,available_qty:string,source_type:string,source_id:int,reason_code:string,explanation:string} $input */
    public function __invoke(array $input, User $actor): NegativeStockOverrideRequest
    {
        return DB::transaction(function () use ($input, $actor): NegativeStockOverrideRequest {
            $company = Company::query()->lockForUpdate()->findOrFail(app(CompanyContext::class)->id());
            if ($company->negative_stock_mode !== NegativeStockMode::AuthorisedOverride) {
                throw new DomainInvariantException('Negative-stock overrides are disabled.', 'negative_stock_mode');
            }
            $class = NegativeStockMovementClass::from($input['movement_class']);
            $reasonCode = NegativeStockReasonCode::from($input['reason_code']);
            if (! $company->{$class->policyField()}) {
                throw new DomainInvariantException('This movement class is not eligible for negative stock.', 'movement_class');
            }
            $item = Item::query()->findOrFail($input['item_id']);
            Location::query()->findOrFail($input['location_id']);
            if (($input['batch_id'] ?? null) !== null && ! StockBatch::query()->where('item_id', $item->id)->whereKey($input['batch_id'])->exists()) {
                throw new DomainInvariantException('The batch must belong to the requested item.', 'batch_id');
            }
            if (! $item->is_stocked) {
                throw new DomainInvariantException('Negative-stock overrides require a stocked item.', 'item_id');
            }
            if ($item->tracking === ItemTracking::Serial || $item->valuation_method === ValuationMethod::Specific) {
                throw new DomainInvariantException('Serial and SPECIFIC negative stock is never permitted.', 'item_id');
            }
            if (trim($input['explanation']) === '') {
                throw new DomainInvariantException('A written explanation is required.', 'explanation');
            }
            $requested = BigDecimal::of($input['requested_qty']);
            $available = BigDecimal::of($input['available_qty']);
            $shortage = $requested->minus($available);
            if ($requested->isLessThanOrEqualTo(0) || $available->isLessThan(0) || $shortage->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException('An override request must describe a genuine positive shortage.', 'shortage_qty');
            }
            $permission = $company->negative_stock_authorisation_mode === NegativeStockAuthorisationMode::ImmediateWithReview ? 'inventory.negative-stock.override' : 'inventory.negative-stock.request';
            if (! $actor->can($permission)) {
                throw new DomainInvariantException('The actor is not permitted to request this negative-stock workflow.', 'requested_by');
            }
            $existing = NegativeStockOverrideRequest::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                $same = $existing->item_id === $item->id
                    && $existing->location_id === $input['location_id']
                    && $existing->batch_id === ($input['batch_id'] ?? null)
                    && $existing->movement_class === $class
                    && BigDecimal::of($existing->requested_qty)->isEqualTo($requested)
                    && BigDecimal::of($existing->available_qty)->isEqualTo($available)
                    && $existing->reason_code === $reasonCode
                    && $existing->explanation === trim($input['explanation'])
                    && $existing->requested_by === $actor->id;
                if (! $same) {
                    throw new DomainInvariantException('This source already has a different negative-stock override request.', 'source_id');
                }

                return $existing;
            }

            return NegativeStockOverrideRequest::query()->create(array_merge($input, ['explanation' => trim($input['explanation']),
                'shortage_qty' => (string) $shortage, 'authorisation_mode' => $company->negative_stock_authorisation_mode,
                'allow_self_review' => $company->negative_stock_allow_self_review,
                'requested_by' => $actor->id, 'requested_at' => now(),
                'review_due_at' => $company->negative_stock_authorisation_mode === NegativeStockAuthorisationMode::ImmediateWithReview ? now()->addHours($company->negative_stock_review_sla_hours) : null,
            ]));
        });
    }
}
