<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Formulation;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class ResolveFormulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'output_item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'customer_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('customer_accounts')],
        ];
    }
}
