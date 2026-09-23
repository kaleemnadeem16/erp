<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\RateDimension;

use App\Modules\Estimating\Models\RateDimension;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRateDimensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var RateDimension|null $rateDimension */
        $rateDimension = $this->route('rate_dimension');

        return [
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('rate_dimensions', 'code', $rateDimension?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
