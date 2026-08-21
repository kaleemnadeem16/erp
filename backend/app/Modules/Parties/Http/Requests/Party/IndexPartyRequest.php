<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:status,search'],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
