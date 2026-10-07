<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use App\Support\CompanyContext;

/**
 * The screen-facing form of a grouped transfer: finds both locations' current evidence itself (a person never handles evidence ids), derives the
 * movement's source identity from the request key so a retry is idempotent, and returns the posting with both new breakdowns. All rules — what arrives
 * sealed and what arrives loose, the destination needing fresh evidence or no stock — stay in TransferPackagedStock.
 */
final class TransferGroupedStock
{
    private const CHAIN = ['COUNTED', 'OPEN', 'UNPACK', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'RECEIPT_IN', 'COUNT_VARIANCE', 'RECEIPT_REVERSAL'];

    /**
     * @param  array{item_id:int, from_location_id:int, to_location_id:int, batch_id?:int|null, request_key:string, reason:string, reference?:string|null, draw:list<array<string,mixed>>, quantity?:string|null}  $input
     * @return array{move:StockMove, source:PackageOperation, destination:PackageOperation, source_balance:array<string,mixed>|null, destination_balance:array<string,mixed>|null}
     */
    public function __invoke(array $input, User $actor): array
    {
        if (! $actor->can('inventory.package-state.transfer') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package transfer and view authority in the current company is required.');
        }
        $batchId = $input['batch_id'] ?? null;
        $key = trim($input['request_key']);
        // A retry of a request that already posted must present the facts it did then: the evidence it was based on and its moment.
        $earlier = PackageOperation::query()->where('request_key', $key)->where('evidence_kind', 'TRANSFER_OUT')->first();
        if ($earlier !== null) {
            $source = $this->beforeSteps($earlier, $key);
            $incoming = PackageOperation::query()->where('request_key', $key.'#in')->firstOrFail();
            $destinationId = $incoming->predecessor_id;
            $movedAt = StockMove::query()->findOrFail($earlier->stock_move_id)->moved_at;
        } else {
            $source = $this->head($input['item_id'], $input['from_location_id'], $batchId);
            $destinationId = $this->head($input['item_id'], $input['to_location_id'], $batchId)?->id;
            $movedAt = now();
            if ($source === null) {
                throw new DomainInvariantException('This location needs a package count before stock can be moved by package.', null, 'NEEDS_COUNT_FIRST');
            }
        }
        $sourceId = (int) hexdec(substr(hash('sha256', app(CompanyContext::class)->id().'|stock-transfer|'.$key), 0, 15));
        $reason = trim($input['reason']).(isset($input['reference']) && trim((string) $input['reference']) !== '' ? ' ['.trim((string) $input['reference']).']' : '');
        $result = app(TransferPackagedStock::class)([
            'source_predecessor_id' => $source->id, 'destination_predecessor_id' => $destinationId, 'to_location_id' => $input['to_location_id'],
            'request_key' => $key, 'reason' => $reason, 'source_type' => 'MANUAL_PACKAGE_TRANSFER', 'source_id' => $sourceId, 'moved_at' => $movedAt,
            'draw' => $input['draw'], 'quantity' => $input['quantity'] ?? null,
        ], $actor);
        $read = app(ReadPackageBalances::class);
        $find = fn (int $location) => collect($read($input['item_id'], $location, $batchId, $actor))->first();

        return [
            'move' => $result['move'], 'source' => $result['source'], 'destination' => $result['destination'],
            'source_balance' => $find($input['from_location_id']), 'destination_balance' => $find($input['to_location_id']),
        ];
    }

    private function head(int $itemId, int $locationId, ?int $batchId): ?PackageOperation
    {
        return PackageOperation::query()->where(['item_id' => $itemId, 'location_id' => $locationId, 'batch_id' => $batchId])
            ->whereIn('evidence_kind', self::CHAIN)->orderByDesc('id')->first();
    }

    /** The evidence the request was first based on: the outgoing leg's predecessor, stepping back over any opening steps the request itself recorded. */
    private function beforeSteps(PackageOperation $out, string $key): PackageOperation
    {
        $operation = PackageOperation::query()->findOrFail($out->predecessor_id);
        while (str_starts_with($operation->request_key, $key.'#step') && $operation->predecessor_id !== null) {
            $operation = PackageOperation::query()->findOrFail($operation->predecessor_id);
        }

        return $operation;
    }
}
