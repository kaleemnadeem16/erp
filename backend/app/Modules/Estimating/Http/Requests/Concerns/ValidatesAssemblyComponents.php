<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Concerns;

use App\Modules\Estimating\Support\AssemblyComponentKind;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ImplicitClosureRule;
use Illuminate\Validation\Rule;

/**
 * Shared by StoreAssemblyVersionRequest and UpdateAssemblyVersionRequest —
 * unlike FormulationLine's few short rules, this is complex enough
 * (kind-dependent cross-field requirements) that duplicating it verbatim
 * risked drift between the two call sites, so it's a trait instead of the
 * usual per-request copy.
 *
 * Each cross-field rule is a closure attached directly to the specific
 * wildcard field it governs (e.g. `components.*.item_id`), not one closure
 * attached to the whole `components` array — a closure rule's $fail()
 * always files under the attribute the rule itself is registered against,
 * so a single components-level closure can't produce a
 * `components.N.item_id`-keyed error no matter what the message string
 * says; it would only ever produce a `components`-keyed one.
 */
trait ValidatesAssemblyComponents
{
    /** @return array<string, mixed> */
    protected function componentFieldRules(): array
    {
        return [
            'components' => ['sometimes', 'array'],
            'components.*.kind' => ['required', Rule::enum(AssemblyComponentKind::class)],
            // No 'sometimes' on item_id/rate_override — their requiredness
            // depends on sibling `kind`, and 'sometimes'/'nullable' both
            // cause a *plain* closure to be skipped whenever the value is
            // absent or empty, which is exactly the one case the
            // requiredness closure needs to see. ImplicitClosureRule opts
            // that one check back in; the plain forbidForNonMaterial
            // closure stays a normal (skippable) rule since it only ever
            // needs to act on a *present* value.
            'components.*.item_id' => [
                'nullable', 'integer', CompanyScoped::exists('items'),
                new ImplicitClosureRule($this->requireItemForMaterialLabourPlant()),
                $this->forbidForNonMaterial('item_id'),
            ],
            'components.*.formulation_id' => [
                'sometimes', 'nullable', 'integer', CompanyScoped::exists('formulations'),
                $this->forbidForNonMaterial('formulation_id'),
            ],
            'components.*.application_rate_id' => [
                'sometimes', 'nullable', 'integer', CompanyScoped::exists('application_rates'),
                $this->forbidForNonMaterial('application_rate_id'),
            ],
            'components.*.qty_per_work_unit' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'components.*.uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'components.*.wastage_pct' => ['sometimes', 'decimal:0,4', 'gte:0', $this->onlyMaterialMayOverride('wastage_pct', '0')],
            'components.*.multiplier' => ['sometimes', 'decimal:0,4', 'gt:0', $this->onlyMaterialMayOverride('multiplier', '1')],
            'components.*.rate_override' => [
                'nullable', 'decimal:0,4', 'gte:0',
                new ImplicitClosureRule($this->requireRateOverrideForSubcontract()),
            ],
            'components.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** $attribute is already fully expanded by Laravel, e.g. "components.3.item_id". */
    private function componentIndex(string $attribute): ?string
    {
        preg_match('/^components\.(\d+)\./', $attribute, $matches);

        return $matches === [] ? null : $matches[1];
    }

    private function siblingKind(string $attribute): ?string
    {
        $index = $this->componentIndex($attribute);

        return $index === null ? null : $this->input("components.{$index}.kind");
    }

    /** MATERIAL/LABOUR/PLANT need item_id unless it's MATERIAL with application_rate_id set instead. */
    private function requireItemForMaterialLabourPlant(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $kind = $this->siblingKind($attribute);
            $index = $this->componentIndex($attribute);
            $hasApplicationRate = $index !== null && ! empty($this->input("components.{$index}.application_rate_id"));

            if ($kind === AssemblyComponentKind::Material->value && empty($value) && ! $hasApplicationRate) {
                $fail('A MATERIAL component needs an item_id or an application_rate_id.');
            }

            if (in_array($kind, [AssemblyComponentKind::Labour->value, AssemblyComponentKind::Plant->value], true) && empty($value)) {
                $fail("A {$kind} component requires an item_id.");
            }
        };
    }

    /** formulation_id/application_rate_id are MATERIAL-only; item_id is forbidden for SUBCONTRACT. */
    private function forbidForNonMaterial(string $field): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($field): void {
            if (empty($value)) {
                return;
            }

            $kind = $this->siblingKind($attribute);

            if ($field === 'item_id' && $kind === AssemblyComponentKind::Subcontract->value) {
                $fail('SUBCONTRACT components cannot reference an item.');
            }

            if (in_array($field, ['formulation_id', 'application_rate_id'], true) && $kind !== AssemblyComponentKind::Material->value) {
                $fail("Only MATERIAL components may reference {$field}.");
            }
        };
    }

    /**
     * wastage_pct/multiplier only apply to MATERIAL — see
     * docs/domain/assemblies.md#cost-roll-up, whose formula never reads
     * them for LABOUR/PLANT/SUBCONTRACT. Rejecting a non-default value here
     * instead of silently ignoring it at roll-up time means what's stored
     * never diverges from what's used.
     */
    private function onlyMaterialMayOverride(string $field, string $default): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($field, $default): void {
            $kind = $this->siblingKind($attribute);

            if ($kind !== AssemblyComponentKind::Material->value && bccomp((string) $value, $default, 4) !== 0) {
                $fail("{$field} does not apply to {$kind} components and must stay at its default ({$default}).");
            }
        };
    }

    /** rate_override is SUBCONTRACT's only value — there is no other cost source for it. */
    private function requireRateOverrideForSubcontract(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $kind = $this->siblingKind($attribute);

            if ($kind === AssemblyComponentKind::Subcontract->value && empty($value)) {
                $fail("A SUBCONTRACT component's rate_override is required — it is the component's only value.");
            }
        };
    }
}
