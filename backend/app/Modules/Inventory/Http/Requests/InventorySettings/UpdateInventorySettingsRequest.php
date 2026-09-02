<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventorySettings;

use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\InventoryLocationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventorySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inventory_location_mode' => ['sometimes', Rule::enum(InventoryLocationMode::class)],
            'default_avco_pool_scope' => ['sometimes', Rule::enum(AvcoPoolScope::class)],
        ];
    }
}
