<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\QualityDisposition;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\DispositionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQualityDispositionRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:disposition'],
            'filter.disposition' => ['sometimes', Rule::enum(DispositionOutcome::class)],
        ], $this->listQueryRules(['disposed_at', 'qty'], []));
    }
}
