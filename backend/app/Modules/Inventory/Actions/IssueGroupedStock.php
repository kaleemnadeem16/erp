<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use App\Support\CompanyContext;

/**
 * The screen-facing form of a grouped issue: finds the scope's current evidence itself (the person never handles evidence ids), derives the movement's
 * source identity from the request key so a retry of the same key is idempotent, and returns the posting with the new breakdown. All rules stay in
 * IssuePackagedStock.
 */
final class IssueGroupedStock
{
    private const CHAIN = PackageOperation::CHAIN;

    /**
     * @param  array{item_id:int, location_id:int, batch_id?:int|null, request_key:string, reason:string, reference?:string|null, draw:list<array<string,mixed>>, quantity?:string|null}  $input
     * @return array{move:StockMove, operation:PackageOperation, balance:array<string,mixed>}
     */
    public function __invoke(array $input, User $actor): array
    {
        if (! $actor->can('inventory.package-state.issue') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package issue and view authority in the current company is required.');
        }
        $batchId = $input['batch_id'] ?? null;
        $key = trim($input['request_key']);
        // A retry of a request that already posted must present the same facts it did then: the evidence it was based on and its moment.
        $earlier = PackageOperation::query()->where('request_key', $key)->where('evidence_kind', 'ISSUE')->first();
        $head = $earlier !== null
            ? $this->before($earlier, $key)
            : PackageOperation::query()->where(['item_id' => $input['item_id'], 'location_id' => $input['location_id'], 'batch_id' => $batchId])
                ->whereIn('evidence_kind', self::CHAIN)->orderByDesc('id')->first();
        if ($head === null) {
            throw new DomainInvariantException('This location needs a package count before stock can be taken by package.', null, 'NEEDS_COUNT_FIRST');
        }
        // A positive 60-bit identity from the company and key: stable for retries, and distinct per request.
        $sourceId = (int) hexdec(substr(hash('sha256', app(CompanyContext::class)->id().'|stock-issue|'.$key), 0, 15));
        $reason = trim($input['reason']).(isset($input['reference']) && trim((string) $input['reference']) !== '' ? ' ['.trim((string) $input['reference']).']' : '');
        $result = app(IssuePackagedStock::class)([
            'predecessor_id' => $head->id, 'request_key' => $key, 'reason' => $reason, 'source_type' => 'MANUAL_PACKAGE_ISSUE', 'source_id' => $sourceId,
            'moved_at' => $earlier !== null ? StockMove::query()->findOrFail($earlier->stock_move_id)->moved_at : now(), 'draw' => $input['draw'], 'quantity' => $input['quantity'] ?? null,
        ], $actor);
        $balance = collect(app(ReadPackageBalances::class)($input['item_id'], $input['location_id'], $batchId, $actor))->first();

        return ['move' => $result['move'], 'operation' => $result['operation'], 'balance' => $balance];
    }

    /** The evidence the request was first based on: the issue's predecessor, stepping back over any opening steps the request itself recorded. */
    private function before(PackageOperation $issue, string $key): PackageOperation
    {
        $operation = PackageOperation::query()->findOrFail($issue->predecessor_id);
        while (str_starts_with($operation->request_key, $key.'#step') && $operation->predecessor_id !== null) {
            $operation = PackageOperation::query()->findOrFail($operation->predecessor_id);
        }

        return $operation;
    }
}
