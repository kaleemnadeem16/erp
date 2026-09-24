<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\OverheadPolicy;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Modules\Estimating\Support\OverheadDriverType;
use App\Modules\Estimating\Support\OverheadScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOverheadPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.overhead-policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $scopeType = $this->input('scope_type');
        $driverType = $this->input('driver_type');
        $isPercentageDriver = in_array($driverType, [
            OverheadDriverType::PctMaterial->value, OverheadDriverType::PctLabour->value, OverheadDriverType::PctDirectCost->value,
        ], true);

        return [
            'scope_type' => ['required', Rule::enum(OverheadScopeType::class)],
            // scope_id targets assembly_groups or assemblies depending on
            // scope_type, so its existence check can't be a single
            // Rule::exists() call — see the closure below. Plain Eloquent
            // queries (not CompanyScoped::exists()) are enough here since
            // Assembly::query()/AssemblyGroup::query() already carry the
            // BelongsToCompany global scope.
            'scope_id' => [
                'prohibited_if:scope_type,'.OverheadScopeType::Company->value,
                'required_unless:scope_type,'.OverheadScopeType::Company->value,
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($scopeType): void {
                    if ($value === null) {
                        return; // required_unless already reports a missing scope_id.
                    }
                    if ($scopeType === OverheadScopeType::AssemblyGroup->value
                        && ! AssemblyGroup::query()->whereKey($value)->exists()) {
                        $fail('The selected assembly group does not exist.');
                    }
                    if ($scopeType === OverheadScopeType::Assembly->value
                        && ! Assembly::query()->whereKey($value)->exists()) {
                        $fail('The selected assembly does not exist.');
                    }
                },
            ],
            'driver_type' => ['required', Rule::enum(OverheadDriverType::class)],
            'rate' => [
                'required', 'decimal:0,6', 'gte:0',
                ...($isPercentageDriver ? ['lte:100'] : []),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
