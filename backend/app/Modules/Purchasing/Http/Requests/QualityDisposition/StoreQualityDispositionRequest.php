<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\QualityDisposition;

use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQualityDispositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.quality-disposition.post') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'disposition' => ['required', 'string', Rule::in(array_column(DispositionOutcome::cases(), 'value'))],
            'qty' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'reason' => ['required', 'string', 'max:500'],
            'source_disposition_id' => ['nullable', 'integer', CompanyScoped::exists('quality_dispositions')],
        ];
    }
}
