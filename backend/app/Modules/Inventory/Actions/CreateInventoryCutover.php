<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Support\Facades\DB;

final class CreateInventoryCutover
{
    /** @param array{mode:string,cutover_at:mixed,source_system:string,target_valuation_policy:array<string,mixed>,control_quantity:string,control_value:string,base_control_value:string} $input */
    public function __invoke(array $input, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.create')) {
            throw new DomainInvariantException('The actor is not permitted to create an inventory cutover.', 'created_by');
        }
        WarehouseScope::requireUnrestricted($actor, 'inventory.cutover.create', 'create an inventory cutover');
        if (trim($input['source_system']) === '' || $input['target_valuation_policy'] === []) {
            throw new DomainInvariantException('Source system and target valuation policy are required.');
        }
        $input['target_valuation_policy'] = $this->normalisePolicy($input['target_valuation_policy']);

        return DB::transaction(function () use ($input, $actor): InventoryCutover {
            return InventoryCutover::query()->create([
                ...$input,
                'mode' => InventoryCutoverMode::from($input['mode']),
                'source_system' => trim($input['source_system']),
                'created_by' => $actor->id,
            ])->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, array<string, mixed>>
     */
    private function normalisePolicy(array $policy): array
    {
        $normalised = [];
        foreach ($policy as $itemCode => $definition) {
            if (trim((string) $itemCode) === '' || ! is_array($definition)) {
                throw new DomainInvariantException('Each target valuation policy needs an item code and definition.', 'target_valuation_policy');
            }
            $method = ValuationMethod::tryFrom((string) ($definition['method'] ?? ''));
            $scope = isset($definition['scope']) ? AvcoPoolScope::tryFrom((string) $definition['scope']) : null;
            if ($method === null || ($method === ValuationMethod::Avco) !== ($scope !== null)) {
                throw new DomainInvariantException('Each target policy needs a valid method and AVCO requires exactly one scope.', 'target_valuation_policy');
            }
            if (isset($definition['revaluation_basis']) && ! is_array($definition['revaluation_basis'])) {
                throw new DomainInvariantException('A revaluation basis must be structured evidence.', 'target_valuation_policy');
            }
            $normalised[strtolower(trim((string) $itemCode))] = [
                'method' => $method->value,
                'scope' => $scope?->value,
                'revaluation_basis' => $definition['revaluation_basis'] ?? null,
            ];
        }

        return $normalised;
    }
}
