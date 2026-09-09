<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowInventoryCutoverRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['batches', 'policyTransitions', 'negativeOpenings', 'creator']);
    }
}
