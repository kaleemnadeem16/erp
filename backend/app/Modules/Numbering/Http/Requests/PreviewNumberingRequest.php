<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\NumberingAccess;
use App\Modules\Numbering\Support\ScopePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewNumberingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(NumberingAccess::class)->canView($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:50'],
            'template' => ['required', 'string', 'max:255'],
            'scope_period' => ['required', Rule::enum(ScopePeriod::class)],
            'scope_dimensions' => ['present', 'array'],
            'scope_dimensions.*' => ['string', 'max:30'],
            'continuity' => ['required', Rule::enum(Continuity::class)],
            'series_code' => ['nullable', 'string', 'max:30'],
            'at' => ['nullable', 'date'],
        ];
    }
}
