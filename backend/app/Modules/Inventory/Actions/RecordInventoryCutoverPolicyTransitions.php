<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverPolicyTransition;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;

/** Internal to ValidateInventoryCutover; records policy provenance but never posts stock. */
final class RecordInventoryCutoverPolicyTransitions
{
    public function record(InventoryCutover $cutover): void
    {
        $facts = $cutover->sourceFacts()
            ->select(['item_id', 'source_valuation_method', 'source_avco_scope', 'source_valuation_pool_identity'])
            ->distinct()
            ->get();

        foreach ($facts as $fact) {
            $item = Item::query()->findOrFail($fact->item_id);
            $policy = $cutover->target_valuation_policy[$item->code] ?? null;
            if (! is_array($policy)) {
                throw new DomainInvariantException("Target valuation policy is missing for item {$item->code}.", 'target_valuation_policy');
            }
            $targetMethod = ValuationMethod::from((string) ($policy['method'] ?? ''));
            $targetScope = isset($policy['scope']) ? AvcoPoolScope::from((string) $policy['scope']) : null;
            if (($targetMethod === ValuationMethod::Avco) !== ($targetScope !== null)) {
                throw new DomainInvariantException('Target AVCO scope is required only for an AVCO target method.', 'target_valuation_policy');
            }
            $identity = [
                'inventory_cutover_id' => $cutover->id,
                'item_id' => $item->id,
                'source_valuation_method' => $fact->source_valuation_method,
                'source_avco_scope' => $fact->source_avco_scope,
                'source_pool_identity' => $fact->source_valuation_pool_identity,
            ];
            $values = [
                'target_valuation_method' => $targetMethod,
                'target_avco_scope' => $targetScope,
                'effective_at' => $cutover->cutover_at,
                'revaluation_basis' => $policy['revaluation_basis'] ?? null,
            ];
            $existing = InventoryCutoverPolicyTransition::query()->where($identity)->first();
            if ($existing === null) {
                InventoryCutoverPolicyTransition::query()->create([...$identity, ...$values]);
            }
        }
    }
}
