<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRecalculationRequestRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:movement_kind,search'],
            'filter.movement_kind' => ['sometimes', Rule::enum(RecalculationMovementKind::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['requested_at', 'review_due_at'], ['item', 'location', 'destinationLocation', 'batch']));
    }
}
