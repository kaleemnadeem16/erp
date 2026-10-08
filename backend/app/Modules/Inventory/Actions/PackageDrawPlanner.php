<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The part of a grouped package movement that issue and transfer share (ADR 0090): parsing the declared draw, recording any opening
 * steps as their own OPEN/UNPACK operations, and checking whole-package counts and loose quantity against the composition. Nothing here
 * posts stock.
 */
final class PackageDrawPlanner
{
    /**
     * Parse and normalise the declared draw lines.
     *
     * @param  list<array<string,mixed>>  $draw
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>
     */
    public function parse(array $draw): array
    {
        return $this->lines($draw);
    }

    /**
     * Record opening steps in order, then return the SEALED/LOOSE draws and the composition they apply to.
     *
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>  $lines
     * @param  array<string,mixed>  $current  fresh replay of the current predecessor
     * @return array{draws:list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>, current:array<string,mixed>}
     */
    public function prepare(array $lines, array $current, string $key, string $reason, string $what, User $actor): array
    {
        $draws = [];
        $step = 0;
        foreach ($lines as $line) {
            if ($line['kind'] === 'SEALED' || $line['kind'] === 'LOOSE') {
                $draws[] = $line;

                continue;
            }
            $state = null;
            foreach ($current['composition'] as $row) {
                if ($row['form'] === 'SEALED' && $row['availability_evidence'] === 'USABLE' && $row['packaging_id'] === $line['packaging_id']) {
                    $state = $row;

                    break;
                }
            }
            if ($state === null) {
                throw new DomainInvariantException('The draw opens a sealed package that is not on hand.');
            }
            $opened = app(PostPackageOpening::class)([
                'operation_id' => $current['operation_id'], 'state_id' => $state['state_id'], 'count' => $line['count'],
                'kind' => $line['kind'] === 'UNPACK' ? 'UNPACK' : 'OPEN', 'request_key' => $key.'#step'.(++$step),
                'reason' => 'Opened within '.$what.' '.$key.': '.$reason,
            ], $actor);
            $current = $this->fresh($opened->id, $actor);
            if ($line['kind'] === 'OPEN_AND_DRAW') {
                if (BigDecimal::of((string) $line['quantity'])->isGreaterThan(BigDecimal::of($state['content_quantity'])->multipliedBy($line['count']))) {
                    throw new DomainInvariantException('A quantity drawn from opened packages cannot exceed what they held.');
                }
                $draws[] = ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => $line['quantity']];
            }
        }
        if ($draws === []) {
            throw new DomainInvariantException('The draw must take something.');
        }

        return ['draws' => $draws, 'current' => $current];
    }

    /**
     * Replays evidence and refuses unless it is the fresh, reconciled, full-scope truth for this stock.
     *
     * @return array<string,mixed>
     */
    public function fresh(int $operationId, User $actor): array
    {
        $snapshot = app(ReplayPackageCheckpoint::class)($operationId, $actor);
        if (! $snapshot['matches_current_stock']) {
            throw new DomainInvariantException('Grouped package stock is not authoritative for this item and location: a fresh counted checkpoint is required ('.implode(', ', $snapshot['mismatch_reasons']).').', null, 'STALE_EVIDENCE');
        }

        return $snapshot;
    }

    /**
     * Validates the whole-package counts and loose quantity against the composition and fills the sealed quantities.
     *
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>  $draws
     * @param  list<array<string,mixed>>  $composition
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>
     */
    public function quantify(array $draws, array $composition, string $availability = 'USABLE'): array
    {
        return $this->withQuantities($draws, $composition, $availability);
    }

    public function exact(string $quantity, string $what): BigDecimal
    {
        return $this->exactQuantity($quantity, $what);
    }

    /**
     * @param  list<array<string,mixed>>  $draw
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>
     */
    private function lines(array $draw): array
    {
        $lines = [];
        foreach ($draw as $line) {
            $kind = $line['kind'] ?? null;
            $count = $line['count'] ?? null;
            $packaging = $line['packaging_id'] ?? null;
            if (isset($line['container_id'])) {
                throw new DomainInvariantException('Grouped stock does not identify individual containers.');
            }
            if (in_array($kind, ['SEALED', 'UNPACK', 'OPEN_AND_DRAW'], true)) {
                if (! is_int($packaging) || ! is_int($count) || $count < 1) {
                    throw new DomainInvariantException('Sealed lines need a package level and a positive whole count.');
                }
            } elseif ($kind !== 'LOOSE') {
                throw new DomainInvariantException('A draw line must be SEALED, LOOSE, UNPACK or OPEN_AND_DRAW.');
            }
            $quantity = in_array($kind, ['LOOSE', 'OPEN_AND_DRAW'], true) ? (string) $this->exactQuantity((string) ($line['quantity'] ?? ''), 'A drawn quantity') : null;
            $lines[] = ['kind' => $kind, 'packaging_id' => $kind === 'LOOSE' ? null : $packaging, 'count' => $kind === 'LOOSE' ? null : $count, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * Validates the whole-package counts and loose quantity against the composition and fills the sealed quantities.
     *
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>  $draws
     * @param  list<array<string,mixed>>  $composition
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>
     */
    private function withQuantities(array $draws, array $composition, string $availability = 'USABLE'): array
    {
        $sealed = [];
        $loose = BigDecimal::zero();
        foreach ($composition as $row) {
            if ($row['availability_evidence'] !== $availability) {
                continue;
            }
            if ($row['form'] === 'SEALED') {
                $sealed[$row['packaging_id']] = ['count' => ($sealed[$row['packaging_id']]['count'] ?? 0) + (int) $row['sealed_count'], 'content' => BigDecimal::of($row['content_quantity'])];
            } else {
                $loose = $loose->plus($row['quantity']);
            }
        }
        $result = [];
        foreach ($draws as $draw) {
            if ($draw['kind'] === 'SEALED') {
                $available = $sealed[$draw['packaging_id']] ?? null;
                if ($available === null || $draw['count'] > $available['count']) {
                    throw new DomainInvariantException('The draw takes more sealed packages than are on hand.', null, 'DRAW_NOT_ON_HAND');
                }
                $sealed[$draw['packaging_id']]['count'] -= $draw['count'];
                $draw['quantity'] = $available['content']->multipliedBy($draw['count'])->toScale(4);
            } else {
                $draw['quantity'] = BigDecimal::of($draw['quantity'])->toScale(4);
                if ($draw['quantity']->isGreaterThan($loose)) {
                    throw new DomainInvariantException('The draw takes more open/loose quantity than is on hand.', null, 'DRAW_NOT_ON_HAND');
                }
                $loose = $loose->minus($draw['quantity']);
            }
            $result[] = $draw;
        }

        return $result;
    }

    private function exactQuantity(string $quantity, string $what): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return $value->toScale(4);
            }
        } catch (\InvalidArgumentException|MathException $error) {
            // fall through to the domain error
        }
        throw new DomainInvariantException("{$what} must be positive and no finer than the stock ledger.");
    }
}
