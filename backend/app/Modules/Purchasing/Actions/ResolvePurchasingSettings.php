<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\PurchasingSetting;
use Illuminate\Support\Facades\DB;

final class ResolvePurchasingSettings
{
    public function __invoke(): PurchasingSetting
    {
        return DB::transaction(function (): PurchasingSetting {
            $settings = PurchasingSetting::query()->lockForUpdate()->firstOrCreate([]);

            return PurchasingSetting::query()->findOrFail($settings->company_id);
        });
    }
}
