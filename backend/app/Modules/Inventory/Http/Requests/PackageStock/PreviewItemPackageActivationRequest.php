<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

class PreviewItemPackageActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.package-state.activate') === true && $this->user()->can('inventory.package-state.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['checkpoints' => ['sometimes', 'array'], 'checkpoints.*' => ['integer']];
    }
}
