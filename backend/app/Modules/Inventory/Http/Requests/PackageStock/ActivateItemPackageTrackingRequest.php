<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

class ActivateItemPackageTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.package-state.activate') === true && $this->user()->can('inventory.package-state.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:1000'],
            'checkpoints' => ['required', 'array', 'min:1'], 'checkpoints.*' => ['integer'],
            'tracked_levels' => ['sometimes', 'array'], 'tracked_levels.*' => ['integer'],
        ];
    }
}
