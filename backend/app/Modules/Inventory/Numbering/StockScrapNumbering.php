<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Numbering;

use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Numbering\Support\VariableSupply;
use Carbon\CarbonImmutable;

/**
 * A scrap document is numbered when it is posted; its warehouse is frozen at posting, so it may appear in a number and may be a counter dimension.
 */
final class StockScrapNumbering implements NumberableDocumentType
{
    public const KEY = 'STOCK_SCRAP';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Scrap';
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function becomesOfficial(): string
    {
        return 'when it is posted';
    }

    public function variables(): array
    {
        return [
            new VariableSupply('WAREHOUSE', 'Warehouse the stock leaves'),
        ];
    }

    public function dimensions(): array
    {
        return ['WAREHOUSE'];
    }

    public function routingAttributes(): array
    {
        return ['WAREHOUSE'];
    }

    public function defaultTemplate(): string
    {
        return 'SCR-{SEQ:4}';
    }

    public function defaultScope(): CounterScope
    {
        return CounterScope::company();
    }

    public function defaultContinuity(): Continuity
    {
        return Continuity::AllowGaps;
    }

    public function continuityLocked(): bool
    {
        return false;
    }

    public function managePermission(): string
    {
        return 'inventory.numbering.manage';
    }

    public function sampleContext(CarbonImmutable $at): NumberingContext
    {
        return new NumberingContext(self::KEY, $at, [
            'WAREHOUSE' => new NumberingValue('LHR', 'sample'),
        ], ['WAREHOUSE' => 'LHR']);
    }

    public function knownDimensionValues(): array
    {
        return ['WAREHOUSE' => Warehouse::query()->orderBy('code')->pluck('code')->map(fn ($c): string => (string) $c)->all()];
    }

    /** The context a posted scrap document hands the numbering engine. */
    public static function contextFor(StockScrap $document, CarbonImmutable $postedAt, ?int $actorId = null, ?string $requestedSeries = null): NumberingContext
    {
        $warehouse = Warehouse::query()->find($document->warehouse_id);
        $values = [];
        if ($warehouse !== null) {
            $values['WAREHOUSE'] = new NumberingValue((string) $warehouse->code, 'warehouses#'.$warehouse->id);
        }

        return new NumberingContext(self::KEY, $postedAt, $values, isset($values['WAREHOUSE']) ? ['WAREHOUSE' => $values['WAREHOUSE']->value] : [], $document->id, $requestedSeries, $actorId);
    }
}
