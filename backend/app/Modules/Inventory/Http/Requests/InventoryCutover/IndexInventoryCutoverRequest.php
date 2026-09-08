<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInventoryCutoverRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:status'],
            'filter.status' => ['sometimes', Rule::enum(InventoryCutoverStatus::class)],
        ], $this->listQueryRules(
            ['created_at', 'validated_at', 'approved_at', 'finalised_at'],
            ['batches', 'policyTransitions', 'negativeOpenings', 'creator']
        ));
    }
}
