<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\AvcoPool;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\StockVisibility;
use Illuminate\Foundation\Http\FormRequest;

class ShowAvcoPoolRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return StockVisibility::stock($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'warehouse', 'batch']);
    }
}
