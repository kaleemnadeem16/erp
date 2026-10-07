<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRecalculationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.recalculation.approve') === true
            && WarehouseScope::unrestricted($this->user(), 'inventory.recalculation.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RecalculationApprovalDecision::class)],
            'notes' => ['required', 'string'],
        ];
    }
}
