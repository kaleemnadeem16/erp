<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchasingSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchasingSetting */
class PurchasingSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['over_receipt_mode' => $this->over_receipt_mode->value, 'over_receipt_tolerance_percent' => $this->over_receipt_tolerance_percent, 'purchase_order_required' => $this->purchase_order_required];
    }
}
