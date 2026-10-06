<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptPackageObservation;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use App\Support\ActionAuthorization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateGoodsReceipt
{
    public function __construct(private readonly DraftGoodsReceiptLines $builder) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(GoodsReceipt $receipt, array $attributes, User $actor): GoodsReceipt
    {
        ActionAuthorization::require($actor, 'purchasing.goods-receipt.create', 'edit a draft goods receipt');

        return DB::transaction(function () use ($receipt, $attributes, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new DomainInvariantException('Only draft goods receipts can be edited.', 'status');
            }
            $oldLines = $receipt->lines()->get()->keyBy('id');
            if (GoodsReceiptPackageObservation::query()->whereIn('goods_receipt_line_id', $oldLines->pluck('id'))->exists()) {
                throw new DomainInvariantException('A receipt with recorded package composition cannot replace its lines.', 'lines');
            }
            $lines = $attributes['lines'];
            foreach ($lines as &$line) {
                if (! PurchasingCommercialAccess::canView($actor)) {
                    foreach (['provisional_unit_cost', 'currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source'] as $field) {
                        if (array_key_exists($field, $line)) {
                            throw new DomainInvariantException('The actor is not permitted to override receipt commercial values.', $field);
                        }
                    }
                }
                $matches = $oldLines->where('purchase_order_line_id', $line['purchase_order_line_id']);
                $old = isset($line['id']) ? $oldLines->get($line['id'])
                    : ($matches->count() === 1 ? $matches->first() : $matches->firstWhere('serial_no', $line['serial_no'] ?? null));
                if (isset($line['id']) && $old === null) {
                    throw new DomainInvariantException('The receipt line does not belong to this draft.', 'lines');
                }
                if ($old !== null && $old->purchase_order_line_id === $line['purchase_order_line_id']) {
                    $line['id'] = $old->id;
                } else {
                    unset($line['id']);
                    $old = null;
                }
                if ($old !== null && $old->received_uom_id === $line['received_uom_id']
                    && $old->item_packaging_id === ($line['item_packaging_id'] ?? null)) {
                    $line += Arr::only($old->getAttributes(), ['provisional_unit_cost', 'currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source']);
                }
            }
            unset($line);
            $receipt->update(Arr::only($attributes, ['supplier_account_id', 'physical_arrival_at', 'posting_date']));
            $receipt->lines()->delete();
            $this->builder->writeLines($receipt, $lines, $oldLines->all());

            return $receipt->refresh()->load('lines');
        });
    }
}
