<?php

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\SupplierOffer;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use App\Rules\Support\CompanyScoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintainSupplierOffer
{
    public static function rules(): array
    {
        return ['item_supplier_id' => ['required', 'integer', CompanyScoped::exists('item_suppliers')], 'item_packaging_id' => ['nullable', 'integer', CompanyScoped::exists('item_packagings')],
            'purchase_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')], 'supplier_item_code' => ['nullable', 'string', 'max:255'],
            'preferred' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'minimum_order_qty' => ['nullable', 'decimal:0,4', 'gt:0'], 'order_multiple' => ['nullable', 'decimal:0,4', 'gt:0'],
            'lead_time_days' => ['nullable', 'integer', 'between:0,65535'], 'unit_price' => ['required', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'], 'price_uom_id' => ['nullable', 'integer', CompanyScoped::exists('uoms')], 'price_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from']];
    }

    public function __invoke(array $input, User $actor, ?SupplierOffer $offer = null): SupplierOffer
    {
        if (! $actor->can('purchasing.item-supplier.manage') || ! PurchasingCommercialAccess::canView($actor)) {
            throw new DomainInvariantException('Offer maintenance requires supplier management and commercial access.');
        }

        return DB::transaction(function () use ($input, $offer): SupplierOffer {
            if ($offer) {
                $offer = SupplierOffer::query()->lockForUpdate()->findOrFail($offer->id);
            }
            $values = array_merge($offer?->getAttributes() ?? [], $input);
            foreach (['valid_from', 'valid_to'] as $f) {
                if ($offer && ! array_key_exists($f, $input)) {
                    $values[$f] = $offer->$f?->format('Y-m-d');
                }
            }
            $data = Validator::make($values, self::rules())->validate();
            $rel = ItemSupplier::query()->lockForUpdate()->findOrFail($data['item_supplier_id']);
            $item = Item::query()->findOrFail($rel->item_id);
            if (! empty($data['item_packaging_id'])) {
                $package = ItemPackaging::query()->findOrFail($data['item_packaging_id']);
                if ($package->item_id !== $item->id || ! $package->active || $data['purchase_uom_id'] !== $item->stock_uom_id) {
                    throw new DomainInvariantException('Package offer must match the item and stock unit reference.', 'item_packaging_id');
                }
            } else {
                if (empty($data['price_uom_id'])) {
                    throw new DomainInvariantException('Loose offers require a price unit.', 'price_uom_id');
                }
                app(ItemUomConversionService::class)->stockUnitFactor($item, Uom::query()->findOrFail($data['purchase_uom_id']));
            }
            if (! empty($data['price_uom_id'])) {
                app(ItemUomConversionService::class)->stockUnitFactor($item, Uom::query()->findOrFail($data['price_uom_id']));
            }
            if (! empty($data['preferred']) && ($data['active'] ?? true)) {
                SupplierOffer::query()->where('item_supplier_id', $rel->id)->where('preferred', true)->when($offer, fn ($q) => $q->whereKeyNot($offer->id))->update(['preferred' => false]);
            }
            if ($offer) {
                $offer->update($data);

                return $offer->refresh();
            }

return SupplierOffer::create($data)->refresh();
        });
    }
}
