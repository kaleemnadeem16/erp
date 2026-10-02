<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\NumberingAccess;
use App\Modules\Numbering\Support\ScopePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNumberingSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(NumberingAccess::class)->canManage($this->user(), $this->string('document_type')->toString());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'template' => ['required', 'string', 'max:255'],
            'scope_period' => ['required', Rule::enum(ScopePeriod::class)],
            'scope_dimensions' => ['present', 'array'],
            'scope_dimensions.*' => ['string', 'max:30'],
            'continuity' => ['required', Rule::enum(Continuity::class)],
            'is_selectable' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
