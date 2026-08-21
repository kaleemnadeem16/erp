<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests\ChartOfAccount;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowChartOfAccountRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['parent', 'children']);
    }
}
