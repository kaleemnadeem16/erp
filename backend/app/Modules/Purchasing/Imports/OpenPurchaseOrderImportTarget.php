<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Imports;

use App\Modules\Imports\Contracts\ImportTarget;
use App\Modules\Imports\Support\ImportPurpose;
use Illuminate\Validation\Rule;

/**
 * One staged row is one still-open source PO line. V1 intentionally imports
 * the remaining commitment, not predecessor receipt/follow-on history.
 */
final class OpenPurchaseOrderImportTarget implements ImportTarget
{
    public function purpose(): ImportPurpose
    {
        return ImportPurpose::InventoryCutover;
    }

    public function entityType(): string
    {
        return 'OPEN_PURCHASE_ORDER';
    }

    public function rules(): array
    {
        return [
            'source_purchase_order_id' => ['required', 'string', 'max:255'],
            'source_line_id' => ['required', 'string', 'max:255'],
            'supplier_code' => ['required', 'string', 'max:100'],
            'supplier_site_code' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3'],
            'fx_policy' => ['required', Rule::in(['FLOATING', 'FIXED'])],
            'fx_rate' => ['required', 'decimal:0,9', 'gt:0'],
            'fx_rate_date' => ['required', 'date_format:Y-m-d'],
            'fx_rate_type' => ['required', 'string', 'max:50'],
            'fx_rate_source' => ['required', 'string', 'max:100'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'line_number' => ['required', 'integer', 'between:1,32767'],
            'item_code' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'remaining_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'purchase_uom_code' => ['required', 'string', 'max:50'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'discount_amount' => ['required', 'decimal:0,4', 'min:0'],
            'tax_amount' => ['required', 'decimal:0,4', 'min:0'],
            'line_expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'qa_required' => ['required', 'boolean'],
            'over_receipt_mode' => ['required', Rule::in(['BLOCK', 'ALLOW_WITHIN_TOLERANCE'])],
            'over_receipt_tolerance_percent' => ['required', 'decimal:0,4', 'between:0,100'],
        ];
    }
}
