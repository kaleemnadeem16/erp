<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Modules\Imports\Contracts\ImportTarget;
use App\Modules\Imports\Support\ImportPurpose;
use Illuminate\Validation\Rule;

final class OpeningStateImportTarget implements ImportTarget
{
    public function purpose(): ImportPurpose
    {
        return ImportPurpose::InventoryCutover;
    }

    public function entityType(): string
    {
        return 'OPENING_STATE';
    }

    public function rules(): array
    {
        return [
            'source_record_id' => ['required', 'string', 'max:255'],
            'item_code' => ['required', 'string', 'max:100'],
            'warehouse_code' => ['required', 'string', 'max:100'],
            'location_code' => ['required', 'string', 'max:100'],
            'source_valuation_method' => ['required', Rule::in(['AVCO', 'FIFO', 'SPECIFIC'])],
            'source_avco_scope' => ['nullable', Rule::in(['COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH'])],
            'source_pool_identity' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'decimal:0,4'],
            'uom_code' => ['required', 'string', 'max:50'],
            'unit_cost' => ['nullable', 'decimal:0,4', 'gte:0'],
            'amount' => ['required', 'decimal:0,4'],
            'base_amount' => ['required', 'decimal:0,4'],
            'currency_code' => ['nullable', 'required_with:fx_rate', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'required_with:currency_code', 'decimal:0,9', 'gt:0'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'],
            'source_effective_at' => ['nullable', 'date'],
        ];
    }
}
