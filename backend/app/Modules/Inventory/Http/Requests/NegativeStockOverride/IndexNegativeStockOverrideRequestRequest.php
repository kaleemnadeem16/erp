<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\NegativeStockOverride;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMovementClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNegativeStockOverrideRequestRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:movement_class,authorisation_mode,search'],
            'filter.movement_class' => ['sometimes', Rule::enum(NegativeStockMovementClass::class)],
            'filter.authorisation_mode' => ['sometimes', Rule::enum(NegativeStockAuthorisationMode::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['requested_at'], ['item', 'location', 'batch', 'approval', 'review']));
    }
}
