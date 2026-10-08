<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockScrap;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\StockScrapReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowStockScrapRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return StockScrapReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
