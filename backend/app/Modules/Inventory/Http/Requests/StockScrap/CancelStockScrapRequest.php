<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockScrap;

use Illuminate\Foundation\Http\FormRequest;

class CancelStockScrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.scrap.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
