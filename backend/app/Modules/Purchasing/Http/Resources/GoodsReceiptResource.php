<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Parties\Http\Resources\SupplierAccountResource;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GoodsReceipt */
class GoodsReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $commercial = PurchasingCommercialAccess::canView($request->user());

        return [
            'id' => $this->id,
            'number' => $this->number,
            'supplier_account_id' => $this->supplier_account_id,
            'status' => $this->status->value,
            'physical_arrival_at' => $this->physical_arrival_at->toIso8601String(),
            'recorded_at' => $this->recorded_at->toIso8601String(),
            'posting_date' => $this->posting_date->format('Y-m-d'),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversed_by' => $this->reversed_by,
            'reversal_of_id' => $this->reversal_of_id,
            'correction_reason' => $this->correction_reason,
            'lines_count' => $this->lines_count ?? $this->whenCounted('lines'),
            'total_amount' => $this->when($commercial, function (): ?string {
                if (isset($this->total_amount)) {
                    return (string) BigDecimal::of((string) $this->total_amount)->toScale(4, RoundingMode::HALF_UP);
                }
                if ($this->relationLoaded('lines')) {
                    $sum = BigDecimal::zero();
                    foreach ($this->lines as $line) {
                        $lineAmount = $line->provisional_amount !== null
                            ? BigDecimal::of((string) $line->provisional_amount)
                            : BigDecimal::of((string) $line->received_qty)->multipliedBy(BigDecimal::of((string) $line->provisional_unit_cost));
                        $sum = $sum->plus($lineAmount);
                    }

                    return (string) $sum->toScale(4, RoundingMode::HALF_UP);
                }

                return null;
            }),
            'currency_id' => $this->currency_id ?? ($this->relationLoaded('lines') ? $this->lines->first()?->currency_id : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')),
            'supplier_account' => new SupplierAccountResource($this->whenLoaded('supplierAccount')),
        ];
    }
}
