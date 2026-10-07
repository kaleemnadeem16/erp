<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageActivation;
use App\Modules\Inventory\Models\PackageActivationScope;
use App\Modules\Inventory\Models\StockLayer;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The one atomic transition from quantity-only to grouped package tracking (ADR 0091). It locks the item and every location holding it,
 * proves that each scope holding stock has fresh reconciled counted evidence, and writes the activation, its scopes and the item's new
 * mode together. The database re-proves the same readiness when the item row changes, so no other path can switch the mode on, and
 * nothing is written if anything is missing. There is no deactivation. Quantity-only items that are never activated are untouched.
 */
final class ActivateGroupedPackageTracking
{
    public function __construct(private readonly PreviewGroupedActivation $preview) {}

    /**
     * @param  array{item_id:int, request_key:string, reason:string, checkpoints:list<int>}  $input
     */
    public function __invoke(array $input, User $actor): PackageActivation
    {
        $company = app(CompanyContext::class)->id();
        if ((int) $actor->company_id !== $company || ! $actor->can('inventory.package-state.activate') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package activation and view authority in the current company is required.');
        }
        $key = trim($input['request_key']);
        $reason = trim($input['reason']);
        $checkpoints = array_values(array_unique(array_map('intval', $input['checkpoints'])));
        sort($checkpoints);
        if ($key === '' || strlen($key) > 100 || $reason === '' || $input['item_id'] < 1 || $checkpoints === []) {
            throw new DomainInvariantException('An item, a request key, a reason and the opening count are required.');
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'item' => $input['item_id'], 'reason' => $reason, 'checkpoints' => $checkpoints], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $actor, $company, $key, $reason, $hash, $checkpoints): PackageActivation {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["package-checkpoint:{$company}:{$key}"]);
            $existing = PackageActivation::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new DomainInvariantException('Request key already records a different activation.');
                }

                return $existing;
            }

            // The item first, then its locations in ascending id order, as every stock poster does.
            Item::query()->lockForUpdate()->findOrFail($input['item_id']);
            $locationIds = StockLayer::query()->where('item_id', $input['item_id'])->where('qty_remaining', '>', 0)->distinct()->orderBy('location_id')->pluck('location_id')->all();
            DB::table('locations')->whereIn('id', $locationIds)->orderBy('id')->lockForUpdate()->get();

            $report = $this->preview->report($input['item_id'], $checkpoints, $actor);
            if (! $report['ready']) {
                $reasons = $report['blockers'];
                foreach ($report['scopes'] as $scope) {
                    foreach ($scope['problems'] as $problem) {
                        $reasons[] = "location {$scope['location_id']}: {$problem}";
                    }
                }
                throw new DomainInvariantException('Grouped package tracking cannot be activated: '.implode(' ', $reasons), 'package_tracking_mode');
            }

            $activation = PackageActivation::query()->create([
                'item_id' => $input['item_id'], 'request_key' => $key, 'payload_hash' => $hash, 'actor_id' => $actor->id, 'reason' => $reason,
                'prior_mode' => 'QUANTITY_ONLY', 'new_mode' => 'PACKAGE_BALANCE', 'activated_at' => now(),
                'after_stock_move_id' => (int) DB::table('stock_moves')->max('id'),
            ]);
            foreach ($report['scopes'] as $scope) {
                PackageActivationScope::query()->create([
                    'activation_id' => $activation->id, 'location_id' => $scope['location_id'], 'batch_id' => $scope['batch_id'],
                    'operation_id' => $scope['operation_id'], 'quantity' => $scope['quantity'],
                ]);
            }
            // Written directly: the database trigger, not the model, is the authority on this transition (and re-checks the evidence).
            DB::table('items')->where('id', $input['item_id'])->update(['package_tracking_mode' => 'PACKAGE_BALANCE', 'package_tracking_activation_id' => $activation->id]);

            return $activation->refresh();
        });
    }
}
