<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Numbering;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Numbering\Support\VariableSupply;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Carbon\CarbonImmutable;

/**
 * A goods receipt is numbered when it is posted. The receiving location, and so the warehouse, and the supplier are frozen at
 * posting, so they may appear in a number and the warehouse may be a counter dimension.
 */
final class GoodsReceiptNumbering implements NumberableDocumentType
{
    public const KEY = 'GOODS_RECEIPT';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Goods receipt';
    }

    public function module(): string
    {
        return 'purchasing';
    }

    public function becomesOfficial(): string
    {
        return 'when it is posted';
    }

    public function variables(): array
    {
        return [
            new VariableSupply('WAREHOUSE', 'Receiving warehouse code'),
            new VariableSupply('SUPPLIER_CODE', 'Supplier code'),
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
        return '{SEQ:4}';
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
        return 'purchasing.numbering.manage';
    }

    public function sampleContext(CarbonImmutable $at): NumberingContext
    {
        return new NumberingContext(self::KEY, $at, [
            'WAREHOUSE' => new NumberingValue('LHR', 'sample'),
            'SUPPLIER_CODE' => new NumberingValue('SUP012', 'sample'),
        ], ['WAREHOUSE' => 'LHR']);
    }

    public function knownDimensionValues(): array
    {
        return ['WAREHOUSE' => Warehouse::query()->orderBy('code')->pluck('code')->map(fn ($c): string => (string) $c)->all()];
    }

    /** The context a posted receipt hands the numbering engine. */
    public static function contextFor(GoodsReceipt $receipt, CarbonImmutable $postedAt, ?int $actorId = null, ?string $requestedSeries = null): NumberingContext
    {
        $location = Location::query()->with('warehouse')->find($receipt->location_id);
        $supplier = SupplierAccount::query()->find($receipt->supplier_account_id);
        $values = [];
        if ($location?->warehouse !== null) {
            $values['WAREHOUSE'] = new NumberingValue((string) $location->warehouse->code, 'warehouses#'.$location->warehouse->id);
        }
        if ($supplier !== null) {
            $values['SUPPLIER_CODE'] = new NumberingValue((string) $supplier->code, 'supplier_accounts#'.$supplier->id);
        }

        return new NumberingContext(self::KEY, $postedAt, $values, isset($values['WAREHOUSE']) ? ['WAREHOUSE' => $values['WAREHOUSE']->value] : [], $receipt->id, $requestedSeries, $actorId);
    }
}
