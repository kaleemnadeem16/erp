<?php

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Rules\Support\CompanyScoped;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintainItemPackaging
{
    public static function rules(): array
    {
        return ['item_id' => ['required', 'integer', CompanyScoped::exists('items')], 'code' => ['required', 'string', 'max:100', 'lowercase'],
            'name' => ['required', 'string', 'max:255'], 'stock_quantity' => ['required', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'quantity_precision' => ['nullable', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'], 'barcode' => ['nullable', 'string', 'max:100'],
            'inner_packaging_id' => ['nullable', 'integer', CompanyScoped::exists('item_packagings')], 'inner_count' => ['nullable', 'integer', 'min:1'], 'active' => ['sometimes', 'boolean']];
    }

    public function __invoke(array $input, User $actor, ?ItemPackaging $package = null): ItemPackaging
    {
        if (! $actor->can('inventory.item.manage')) {
            throw new DomainInvariantException('The actor cannot maintain packages.');
        }

        return DB::transaction(function () use ($input, $package): ItemPackaging {
            if ($package) {
                $package = ItemPackaging::query()->lockForUpdate()->findOrFail($package->id);
            }
            $data = Validator::make(array_merge($package?->getAttributes() ?? [], $input), self::rules())->validate();
            if (ItemPackaging::query()->where('item_id', $data['item_id'])->where('code', $data['code'])->when($package, fn ($q) => $q->whereKeyNot($package->id))->exists()) {
                throw new DomainInvariantException('Code already exists for this item.', 'code');
            }
            if (! empty($data['inner_packaging_id'])) {
                $inner = ItemPackaging::query()->lockForUpdate()->findOrFail($data['inner_packaging_id']);
                if ($inner->item_id !== $data['item_id'] || ! $inner->active || empty($data['inner_count']) || ! BigDecimal::of($inner->stock_quantity)->multipliedBy($data['inner_count'])->isEqualTo($data['stock_quantity'])) {
                    throw new DomainInvariantException('Inner package/count must match this item and direct stock content.', 'inner_packaging_id');
                }
            } elseif (! empty($data['inner_count'])) {
                throw new DomainInvariantException('Inner count requires an inner package.', 'inner_count');
            }
            $item = Item::query()->findOrFail($data['item_id']);
            if ($item->quantity_precision !== null && ! BigDecimal::of($data['stock_quantity'])->remainder($item->quantity_precision)->isZero()) {
                throw new DomainInvariantException('Content violates stock increment.', 'stock_quantity');
            }
            if ($package) {
                $package->update($data);

                return $package->refresh();
            }

            return ItemPackaging::create($data)->refresh();
        });
    }
}
