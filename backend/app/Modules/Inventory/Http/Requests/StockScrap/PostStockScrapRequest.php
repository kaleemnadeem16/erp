<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\StockScrap;

use Illuminate\Foundation\Http\FormRequest;

class PostStockScrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.scrap.post') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
