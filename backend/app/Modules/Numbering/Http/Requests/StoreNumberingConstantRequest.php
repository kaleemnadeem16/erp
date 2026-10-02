<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNumberingConstantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('numbering.constant.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            // Present but null retires the constant; the field is required so a forgotten value is never read as a retirement.
            'value' => ['present', 'nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
