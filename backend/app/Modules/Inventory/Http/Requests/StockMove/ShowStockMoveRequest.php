<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockMove;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\StockVisibility;
use Illuminate\Foundation\Http\FormRequest;

class ShowStockMoveRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return StockVisibility::movement($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'from_location', 'to_location', 'batch', 'valuation_entries']);
    }
}
