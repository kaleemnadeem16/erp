<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\NegativeStockOverride;

use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideNegativeStockOverrideRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.negative-stock.approve') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(NegativeStockApprovalDecision::class)],
            'notes' => ['required', 'string'],
        ];
    }
}
