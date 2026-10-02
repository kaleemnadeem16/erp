<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RaiseNumberingCounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('numbering.counter.raise') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'next' => ['required', 'integer', 'min:2', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
