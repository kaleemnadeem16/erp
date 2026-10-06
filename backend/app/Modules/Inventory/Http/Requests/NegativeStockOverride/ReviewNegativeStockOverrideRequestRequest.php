<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\NegativeStockOverride;

use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewNegativeStockOverrideRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.negative-stock.review') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(NegativeStockReviewOutcome::class)],
            'notes' => ['required', 'string'],
        ];
    }
}
