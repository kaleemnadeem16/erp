<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests;

use App\Support\Scope\RecordScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CostBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->can('estimating.internal_cost.view') !== true) {
            return false;
        }
        if (! app(RecordScope::class)->resolve($this->user(), 'estimating.internal_cost.view')->isAll()) {
            throw new HttpResponseException(response()->json(['message' => 'Cost evidence cannot yet be narrowed to your record scope.', 'reason_code' => 'REFUSED_WHEN_LIMITED'], 403));
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
