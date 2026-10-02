<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\NumberingAccess;
use App\Modules\Numbering\Support\ScopePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNumberingVersionRequest extends FormRequest
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
            'template' => ['required', 'string', 'max:255'],
            'scope_period' => ['required', Rule::enum(ScopePeriod::class)],
            'scope_dimensions' => ['present', 'array'],
            'scope_dimensions.*' => ['string', 'max:30'],
            'continuity' => ['required', Rule::enum(Continuity::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
