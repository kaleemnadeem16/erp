<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use App\Modules\Estimating\Support\VarianceDirection;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceMeasure;
use App\Rules\Support\CompanyScoped;
use Closure;
use Illuminate\Validation\Rule;

/** Shared band rules of the store and update requests (ADR 0054). */
trait ValidatesVarianceBands
{
    /** @return array<string, mixed> */
    protected function bandRules(): array
    {
        $configurable = array_map(fn (VarianceEffect $e): string => $e->value, array_filter(VarianceEffect::cases(), fn (VarianceEffect $e): bool => $e->isConfigurable()));

        return [
            'bands' => ['sometimes', 'array', 'max:50'],
            'bands.*.direction' => ['required', Rule::enum(VarianceDirection::class)],
            'bands.*.measure' => ['required', Rule::enum(VarianceMeasure::class)],
            'bands.*.threshold' => [
                'required', 'decimal:0,6', 'gte:0', 'max:999999999999.999999',
                // An over-plan difference can exceed 100 %, an under-plan one cannot (planned cannot go below zero).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1];
                    $percent = in_array($this->input("bands.{$index}.measure"), [VarianceMeasure::Percent->value, VarianceMeasure::CostPercent->value], true);
                    if ($percent && $this->input("bands.{$index}.direction") === VarianceDirection::Under->value && (float) $value > 100) {
                        $fail('An under-plan percentage cannot exceed 100.');
                    }
                },
            ],
            'bands.*.uom_id' => [
                'nullable', 'integer', CompanyScoped::exists('uoms'),
                'required_if:bands.*.measure,'.VarianceMeasure::Quantity->value,
                'prohibited_unless:bands.*.measure,'.VarianceMeasure::Quantity->value,
            ],
            'bands.*.effect' => ['required', Rule::in($configurable)],
        ];
    }

    /** @return array<string, string> */
    protected function bandMessages(): array
    {
        return [
            'bands.*.effect.in' => 'Approval-required cannot be chosen yet: it needs the shared approval workflow, which does not exist.',
            'minimum_effect.in' => 'Approval-required cannot be chosen yet: it needs the shared approval workflow, which does not exist.',
            'bands.*.uom_id.required_if' => 'A quantity band needs a unit.',
            'bands.*.uom_id.prohibited_unless' => 'Only a quantity band has a unit.',
        ];
    }

    /** @return list<string> */
    protected function configurableEffects(): array
    {
        return array_values(array_map(fn (VarianceEffect $e): string => $e->value, array_filter(VarianceEffect::cases(), fn (VarianceEffect $e): bool => $e->isConfigurable())));
    }
}
