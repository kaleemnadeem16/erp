<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Saves a quantity-neutral evidence successor. Does not enable operational package tracking. */
final class PostPackageOpening
{
    /** @param array{operation_id:mixed,state_id:mixed,count:mixed,kind:string,request_key:string,reason:string} $input */
    public function __invoke(array $input, User $actor): PackageOperation
    {
        $company = app(CompanyContext::class)->id();
        if (! $actor->can('inventory.package-state.open') || (int) $actor->company_id !== $company) {
            throw new DomainInvariantException('Package opening authority in the current company is required.');
        }
        // Recheck read authority on retries too; a saved request must not bypass current access.
        if (! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package-state view authority is required to post an opening.');
        }
        $key = trim($input['request_key']);
        $reason = trim($input['reason']);
        if ($key === '' || strlen($key) > 100 || $reason === '') {
            throw new DomainInvariantException('A request key and opening reason are required.');
        }
        if (! is_int($input['operation_id']) || ! is_int($input['state_id']) || ! is_int($input['count'])
            || $input['operation_id'] < 1 || $input['state_id'] < 1 || $input['count'] < 1
            || ! in_array($input['kind'], ['OPEN', 'UNPACK'], true)) {
            throw new DomainInvariantException('Opening requires a checkpoint, sealed selection, positive whole count and OPEN/UNPACK kind.');
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'operation' => $input['operation_id'],
            'state' => $input['state_id'], 'count' => $input['count'], 'kind' => $input['kind'], 'reason' => $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $actor, $company, $key, $reason, $hash): PackageOperation {
            // All evidence writers share this namespace, including an absent key at another location.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["package-checkpoint:{$company}:{$key}"]);
            $existing = PackageOperation::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new DomainInvariantException('Request key already records different package evidence.');
                }

                return $existing;
            }
            $parent = PackageOperation::query()->findOrFail($input['operation_id']);
            $location = Location::query()->lockForUpdate()->findOrFail($parent->location_id);
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Opening requires an active stock location and warehouse.');
            }
            // Preview takes the stable location lock and revalidates full current scope inside this transaction.
            $proposal = app(PreviewPackageOpening::class)($parent->id, $input['state_id'], $input['count'], $input['kind'], $actor);
            $priorStates = PackageState::query()->where('operation_id', $parent->id)->orderBy('id')->get();
            $operation = PackageOperation::query()->create([
                'item_id' => $parent->item_id, 'location_id' => $parent->location_id, 'batch_id' => $parent->batch_id,
                'request_key' => $key, 'payload_hash' => $hash, 'evidence_kind' => $input['kind'],
                'actor_id' => $actor->id, 'reason' => $reason, 'recorded_at' => now(),
                'stock_move_watermark' => $parent->stock_move_watermark, 'predecessor_id' => $parent->id,
                'selected_state_id' => $input['state_id'], 'selected_count' => $input['count'],
            ]);
            foreach ($proposal['composition'] as $row) {
                $origin = $priorStates->first(fn ($prior) => $prior->form === $row['form']
                    && $prior->availability === $row['availability_evidence']
                    && $prior->item_packaging_id === $row['packaging_id']
                    && $prior->packaging_code === $row['packaging_code'] && $prior->packaging_name === $row['packaging_name']
                    && ($prior->content_quantity === null ? $row['content_quantity'] === null
                        : BigDecimal::of($prior->content_quantity)->isEqualTo($row['content_quantity'])));
                $state = PackageState::query()->create([
                    'operation_id' => $operation->id, 'origin_state_id' => $origin?->id,
                    'item_packaging_id' => $row['packaging_id'], 'form' => $row['form'],
                    'availability' => $row['availability_evidence'], 'packaging_code' => $row['packaging_code'],
                    'packaging_name' => $row['packaging_name'], 'sealed_count' => $row['sealed_count'],
                    'content_quantity' => $row['content_quantity'], 'stock_quantity' => $row['quantity'],
                ]);
                foreach ($row['contributions'] as $part) {
                    PackageContribution::query()->create(['state_id' => $state->id,
                        'stock_layer_id' => $part['layer_id'], 'position' => $part['position'], 'quantity' => $part['quantity']]);
                }
            }

            return $operation;
        });
    }
}
