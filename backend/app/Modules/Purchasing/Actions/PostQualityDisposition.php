<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PostQualityDisposition
{
    /** @param array{disposition:string, qty:string, reason:string, source_disposition_id?:int|null} $attributes */
    public function __invoke(GoodsReceiptLine $line, array $attributes, int $actorId, ?string $idempotencyKey = null): QualityDisposition
    {
        $actor = User::withoutGlobalScopes()->findOrFail($actorId);
        if (! $actor->can('purchasing.quality-disposition.post')) {
            throw new DomainInvariantException('The actor is not permitted to post a quality disposition.', 'disposed_by');
        }

        return DB::transaction(function () use ($line, $attributes, $actorId, $idempotencyKey): QualityDisposition {
            $sourceDispositionId = $attributes['source_disposition_id'] ?? null;

            // Locking the line first, then re-checking the idempotency key, is what
            // actually closes the race: two concurrent requests with the same key can
            // both miss an unlocked check before either has inserted. Once serialised
            // on this lock, the second call's re-check sees the first call's committed
            // row. The unique-index catch below is the remaining backstop for two keys
            // racing across *different* lines, which this lock can't serialise.
            $line = GoodsReceiptLine::query()->lockForUpdate()->with(['goodsReceipt', 'purchaseOrderLine'])->findOrFail($line->id);

            if ($idempotencyKey !== null) {
                $existing = QualityDisposition::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $this->assertSamePayload($existing, $line, $attributes, $sourceDispositionId);

                    return $existing;
                }
            }

            PackageWriteScope::refuse($line->item_id, 'a quality disposition');
            if ($line->goodsReceipt->status !== GoodsReceiptStatus::Posted) {
                throw new DomainInvariantException('QA dispositions require a posted goods receipt.', 'goods_receipt_line_id');
            }
            if ($line->purchaseOrderLine === null || ! $line->purchaseOrderLine->qa_required) {
                throw new DomainInvariantException('QA disposition requires an inspection-required receipt line.', 'goods_receipt_line_id');
            }

            if ($sourceDispositionId === null) {
                $capacity = BigDecimal::of($line->stock_qty);
                $allocated = QualityDisposition::query()->where('goods_receipt_line_id', $line->id)
                    ->whereNull('source_disposition_id')->sum('qty');
            } else {
                $parent = QualityDisposition::query()->where('goods_receipt_line_id', $line->id)->find($sourceDispositionId);
                if ($parent === null) {
                    throw new DomainInvariantException('source_disposition_id must belong to the same receipt line.', 'source_disposition_id');
                }
                if ($parent->disposition->isTerminal()) {
                    throw new DomainInvariantException('Cannot post a further disposition against a terminal QA outcome.', 'source_disposition_id');
                }
                $capacity = BigDecimal::of($parent->qty);
                $allocated = QualityDisposition::query()->where('source_disposition_id', $parent->id)->sum('qty');
            }

            $remaining = $capacity->minus(BigDecimal::of((string) $allocated));
            $qty = BigDecimal::of((string) $attributes['qty']);
            if ($qty->isGreaterThan($remaining)) {
                throw new DomainInvariantException('Disposition quantity exceeds pending QA quantity.', 'qty');
            }

            try {
                // A failed insert poisons the enclosing Postgres transaction, so the
                // fallback lookup below a bare catch would also fail — nesting in its
                // own DB::transaction() gives Laravel a SAVEPOINT to roll back to
                // instead, leaving the outer transaction (and the line lock) intact.
                return DB::transaction(fn () => QualityDisposition::query()->create([
                    'goods_receipt_line_id' => $line->id,
                    'source_disposition_id' => $sourceDispositionId,
                    'disposition' => $attributes['disposition'],
                    'qty' => (string) $qty,
                    'reason' => $attributes['reason'],
                    'disposed_by' => $actorId,
                    'disposed_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($idempotencyKey === null) {
                    throw $e;
                }
                $existing = QualityDisposition::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing === null) {
                    throw $e;
                }
                $this->assertSamePayload($existing, $line, $attributes, $sourceDispositionId);

                return $existing;
            }
        });
    }

    /** @param array{disposition:string, qty:string, reason:string, source_disposition_id?:int|null} $attributes */
    private function assertSamePayload(QualityDisposition $existing, GoodsReceiptLine $line, array $attributes, ?int $sourceDispositionId): void
    {
        $matches = $existing->goods_receipt_line_id === $line->id
            && $existing->disposition->value === $attributes['disposition']
            && BigDecimal::of($existing->qty)->isEqualTo(BigDecimal::of((string) $attributes['qty']))
            && $existing->reason === $attributes['reason']
            && $existing->source_disposition_id === $sourceDispositionId;

        if (! $matches) {
            throw new DomainInvariantException('Idempotency-Key was already used for a different quality disposition.', 'idempotency_key');
        }
    }
}
