<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\PurchasingSetting;
use Illuminate\Support\Facades\DB;

final class UpdatePurchasingSettings
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): PurchasingSetting
    {
        return DB::transaction(function () use ($attributes) {
            $settings = PurchasingSetting::query()->lockForUpdate()->firstOrCreate([]);
            $settings->update($attributes);

            return $settings->refresh();
        });
    }
}
