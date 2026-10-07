<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Inventory\Support\WarehouseScope;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only — CreateInventoryCutover itself enforces the domain rules a
 * plain rule set can't express: AVCO requires exactly one scope, non-AVCO
 * must have none. Duplicated here, this would drift.
 *
 * target_valuation_policy is validated by a single closure rather than
 * `target_valuation_policy.*.method`-style dot notation: it's an object
 * keyed by item code (CreateInventoryCutover iterates it as
 * `foreach ($policy as $itemCode => $definition)`), and Scramble reads a
 * wildcard rule as "a list of items", which would generate a client type
 * that silently drops the item-code keys.
 */
class StoreInventoryCutoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.cutover.create') === true
            && WarehouseScope::unrestricted($this->user(), 'inventory.cutover.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(InventoryCutoverMode::class)],
            'cutover_at' => ['required', 'date'],
            'source_system' => ['required', 'string', 'max:255'],
            'target_valuation_policy' => ['required', 'array', 'min:1', $this->policyShape()],
            'control_quantity' => ['required', 'decimal:0,4'],
            'control_value' => ['required', 'decimal:0,4'],
            'base_control_value' => ['required', 'decimal:0,4'],
        ];
    }

    private function policyShape(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach ((array) $value as $itemCode => $definition) {
                if (! is_string($itemCode) || trim($itemCode) === '' || ! is_array($definition)) {
                    $fail('Each target valuation policy entry needs an item code key and a definition.');

                    return;
                }
                if (! isset($definition['method']) || ValuationMethod::tryFrom((string) $definition['method']) === null) {
                    $fail("The target valuation policy for {$itemCode} needs a valid method.");

                    return;
                }
                if (isset($definition['scope']) && AvcoPoolScope::tryFrom((string) $definition['scope']) === null) {
                    $fail("The target valuation policy for {$itemCode} has an invalid scope.");

                    return;
                }
            }
        };
    }
}
