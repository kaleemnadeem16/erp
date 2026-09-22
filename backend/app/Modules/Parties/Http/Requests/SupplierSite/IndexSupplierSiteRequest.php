<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\SupplierSite;

use App\Modules\Parties\Support\SupplierSiteRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSupplierSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:role'],
            'filter.role' => ['sometimes', Rule::enum(SupplierSiteRole::class)],
        ];
    }
}
