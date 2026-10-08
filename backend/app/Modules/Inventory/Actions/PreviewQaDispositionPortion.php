<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Read-only: what a QA disposition of a grouped line would apply to (ADR 0094). Shows the packages still held, the quantity QA has not yet finally decided for the
 * line, and proposes the portion — whole held sealed packages largest first, then the loose quantity — only when it adds up exactly. Nothing is guessed: if no whole
 * packages and loose quantity make the quantity, there is no proposal and the person declares one.
 */
final class PreviewQaDispositionPortion
{
    /** @return array<string,mixed> */
    public function __invoke(GoodsReceiptLine $line, string $quantity, User $actor): array
    {
        if (! $actor->can('purchasing.quality-disposition.post') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Quality-disposition posting and package-state view authority is required.');
        }
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->firstOrFail();
        WarehouseScope::requireLocation($actor, 'inventory.package-state.view', $layer->location_id, 'dispose package-tracked stock');
        $decided = QualityDisposition::query()->where('goods_receipt_line_id', $line->id)
            ->whereIn('disposition', ['PASS', 'ACCEPT_WITH_CONCESSION', 'RETURN_TO_SUPPLIER', 'SCRAP'])->sum('qty');
        $pending = BigDecimal::of($line->stock_qty)->minus((string) $decided)->toScale(4);
        $balance = collect(app(ReadPackageBalances::class)($line->item_id, $layer->location_id, $layer->batch_id, $actor))
            ->first(fn (array $row): bool => $row['location']['id'] === $layer->location_id && $row['batch_id'] === $layer->batch_id);
        $held = $balance['held'] ?? ['sealed' => [], 'loose' => '0.0000', 'total' => '0.0000'];
        $wanted = BigDecimal::of($quantity)->toScale(4, RoundingMode::UNNECESSARY);

        $remaining = $wanted;
        $lines = [];
        $sealed = $held['sealed'];
        usort($sealed, fn (array $a, array $b): int => BigDecimal::of($b['content_quantity'])->compareTo($a['content_quantity']));
        foreach ($sealed as $row) {
            $content = BigDecimal::of($row['content_quantity']);
            $take = min($remaining->dividedBy($content, 0, RoundingMode::DOWN)->toInt(), $row['count']);
            if ($take > 0) {
                $lines[] = ['kind' => 'SEALED', 'packaging_id' => $row['item_packaging_id'], 'count' => $take, 'quantity' => null];
                $remaining = $remaining->minus($content->multipliedBy($take));
            }
        }
        if ($remaining->isGreaterThan(0) && $remaining->isLessThanOrEqualTo($held['loose'])) {
            $lines[] = ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => (string) $remaining->toScale(4)];
            $remaining = BigDecimal::zero();
        }

        return [
            'goods_receipt_line_id' => $line->id, 'quantity' => (string) $wanted, 'pending' => (string) $pending, 'held' => $held,
            'suggested' => $remaining->isZero() && $lines !== [] ? $lines : null,
            'message' => $remaining->isZero() && $lines !== [] ? null : 'No whole held packages and loose quantity add up to '.$wanted.': declare the packages this applies to.',
        ];
    }
}
