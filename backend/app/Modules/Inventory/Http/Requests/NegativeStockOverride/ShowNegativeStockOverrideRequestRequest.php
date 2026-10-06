<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\NegativeStockOverride;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\NegativeStockReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowNegativeStockOverrideRequestRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return NegativeStockReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'location', 'batch', 'approval', 'review']);
    }
}
