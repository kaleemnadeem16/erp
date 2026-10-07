<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;

/**
 * Spreads a counted composition over the stock layers that actually hold the stock (ADR 0092 §2). Deterministic bookkeeping, labelled counted
 * rather than provenance, exactly like a counted checkpoint: sealed cohorts first in the order counted, the open/loose quantity last, each taking
 * from the oldest layers still unassigned. The counted total must equal the layers' total exactly.
 */
final class PackageCountComposition
{
    /**
     * @param  list<array{packaging_id:int|null, packaging_code:string|null, packaging_name:string|null, count:int|null, content_quantity:string|null, quantity:string}>  $rows
     * @param  array<int,string>  $layers  layer id => remaining quantity, oldest first
     * @return list<array<string,mixed>> successor rows in the shape the evidence writers use (origin_state_id null)
     */
    public function __invoke(array $rows, array $layers): array
    {
        usort($rows, fn (array $a, array $b): int => ($a['packaging_id'] === null) <=> ($b['packaging_id'] === null));
        $remaining = array_map(fn (string $qty): BigDecimal => BigDecimal::of($qty), $layers);
        $result = [];
        foreach ($rows as $row) {
            $needed = BigDecimal::of($row['quantity']);
            $contributions = [];
            foreach ($remaining as $layerId => $available) {
                if ($needed->isZero()) {
                    break;
                }
                $take = $available->isLessThan($needed) ? $available : $needed;
                if ($take->isZero()) {
                    continue;
                }
                $contributions[] = ['layer_id' => $layerId, 'position' => count($contributions) + 1, 'quantity' => (string) $take->toScale(4)];
                $remaining[$layerId] = $available->minus($take);
                $needed = $needed->minus($take);
            }
            if (! $needed->isZero()) {
                throw new DomainInvariantException('The counted composition is larger than the stock on hand.');
            }
            $result[] = ['origin_state_id' => null, 'form' => $row['packaging_id'] === null ? 'OPEN_LOOSE' : 'SEALED', 'availability_evidence' => 'USABLE',
                'packaging_id' => $row['packaging_id'], 'packaging_code' => $row['packaging_code'], 'packaging_name' => $row['packaging_name'],
                'sealed_count' => $row['count'], 'content_quantity' => $row['content_quantity'], 'quantity' => (string) BigDecimal::of($row['quantity'])->toScale(4),
                'contributions' => $contributions];
        }
        foreach ($remaining as $left) {
            if (! $left->isZero()) {
                throw new DomainInvariantException('The counted composition is smaller than the stock on hand.');
            }
        }

        return $result;
    }
}
