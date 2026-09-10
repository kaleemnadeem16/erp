<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchasingPolicyChange;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexPurchasingPolicyChangeRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.policy-history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:policy'],
            'filter.policy' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['changed_at'], ['changedBy']));
    }
}
