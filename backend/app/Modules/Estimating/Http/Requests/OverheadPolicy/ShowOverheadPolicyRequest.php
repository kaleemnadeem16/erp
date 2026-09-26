<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\OverheadPolicy;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowOverheadPolicyRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return $this->user()?->can('estimating.policy-history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['changedBy']);
    }
}
