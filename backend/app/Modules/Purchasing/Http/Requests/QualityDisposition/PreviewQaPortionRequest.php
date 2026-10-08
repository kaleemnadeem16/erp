<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\QualityDisposition;

use Illuminate\Foundation\Http\FormRequest;

class PreviewQaPortionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.quality-disposition.post') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999']];
    }
}
