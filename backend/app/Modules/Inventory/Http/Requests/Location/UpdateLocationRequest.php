<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Location;

use App\Modules\Inventory\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {/** @var Location|null $location */ $location = $this->route('location');

        return ['code' => ['sometimes', 'string', 'max:255', Rule::unique('locations')->ignore($location?->id)->where(fn ($q) => $q->where('warehouse_id', $location?->warehouse_id))], 'name' => ['sometimes', 'string', 'max:255'], 'active' => ['sometimes', 'boolean']];
    }
}
