<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\GoodsReceipt;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_account_id' => ['required', 'integer', CompanyScoped::exists('supplier_accounts')],
            'physical_arrival_at' => ['required', 'date'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', CompanyScoped::exists('purchase_order_lines')],
            'lines.*.item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'lines.*.location_id' => ['required', 'integer', 'exists:locations,id'],
            'lines.*.received_qty' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'lines.*.received_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'lines.*.provisional_unit_cost' => ['required', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'lines.*.currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'lines.*.fx_rate' => ['required', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'lines.*.fx_rate_date' => ['required', 'date_format:Y-m-d'],
            'lines.*.fx_rate_type' => ['required', 'string', 'max:50'],
            'lines.*.fx_rate_source' => ['required', 'string', 'max:100'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
