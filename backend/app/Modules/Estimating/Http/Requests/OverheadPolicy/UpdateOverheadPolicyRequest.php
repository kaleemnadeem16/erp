<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\OverheadPolicy;

use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadDriverType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOverheadPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.overhead-policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var OverheadPolicy|null $policy */
        $policy = $this->route('overhead_policy');
        $driverType = $this->input('driver_type', $policy?->driver_type?->value);
        $isPercentageDriver = in_array($driverType, [
            OverheadDriverType::PctMaterial->value, OverheadDriverType::PctLabour->value, OverheadDriverType::PctDirectCost->value,
        ], true);

        return [
            // scope_type/scope_id are deliberately absent — a policy doesn't
            // change what it's scoped to, see UpdateOverheadPolicy.
            'driver_type' => ['sometimes', Rule::enum(OverheadDriverType::class)],
            'rate' => [
                'sometimes', 'decimal:0,6', 'gte:0',
                ...($isPercentageDriver ? ['lte:100'] : []),
            ],
            'reason' => ['sometimes', 'string', 'max:1000'],
        ];
    }
}
