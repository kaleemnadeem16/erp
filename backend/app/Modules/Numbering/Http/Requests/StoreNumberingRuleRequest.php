<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Support\NumberingAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNumberingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var NumberingSeries $series */
        $series = $this->route('series');

        return app(NumberingAccess::class)->canManage($this->user(), $series->doc_type);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.attribute' => ['required', 'string', 'max:30'],
            'conditions.*.operator' => ['required', Rule::in(['EQ', 'IN'])],
            'conditions.*.values' => ['required', 'array', 'min:1'],
            'conditions.*.values.*' => ['required', 'string', 'max:50'],
        ];
    }
}
