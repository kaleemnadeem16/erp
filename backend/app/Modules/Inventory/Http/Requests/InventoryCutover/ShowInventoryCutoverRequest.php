<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\CutoverReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowInventoryCutoverRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return CutoverReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['batches', 'policyTransitions', 'negativeOpenings', 'creator']);
    }
}
