<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ApplicationRate;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Estimating\Support\RecipeReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowApplicationRateRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return RecipeReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'workUom', 'uom', 'dimensionValues']);
    }
}
