<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CreateGoodsReceipt
{
    private const COMMERCIAL_FIELDS = [
        'provisional_unit_cost', 'currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source',
    ];

    public function __construct(private readonly DraftGoodsReceiptLines $builder) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor, ?string $idempotencyKey = null): GoodsReceipt
    {
        if (! $actor->can('purchasing.goods-receipt.create')) {
            throw new DomainInvariantException('The actor is not permitted to create a goods receipt.');
        }
        if (! PurchasingCommercialAccess::canView($actor)) {
            foreach ($attributes['lines'] ?? [] as $line) {
                foreach (self::COMMERCIAL_FIELDS as $field) {
                    if (array_key_exists($field, $line)) {
                        throw new DomainInvariantException('The actor is not permitted to override receipt commercial values.', $field);
                    }
                }
            }
        }

        return DB::transaction(function () use ($attributes, $idempotencyKey): GoodsReceipt {
            if ($idempotencyKey !== null) {
                $existing = GoodsReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing->load('lines');
                }
            }

            $lines = Arr::pull($attributes, 'lines');
            $receipt = GoodsReceipt::query()->create(array_merge($attributes, [
                'recorded_at' => now(), 'idempotency_key' => $idempotencyKey,
            ]));

            $this->builder->writeLines($receipt, $lines);

            return $receipt->refresh()->load('lines');
        });
    }
}
