<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Numbering;

use App\Models\Currency;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Numbering\Support\VariableSupply;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Carbon\CarbonImmutable;

/**
 * A purchase order is numbered when it is issued. Its supplier and currency are frozen at that moment (issue freezes them),
 * so they may appear in a number. The default format is today's: just the zero-padded counter.
 */
final class PurchaseOrderNumbering implements NumberableDocumentType
{
    public const KEY = 'PURCHASE_ORDER';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Purchase order';
    }

    public function module(): string
    {
        return 'purchasing';
    }

    public function becomesOfficial(): string
    {
        return 'when it is issued';
    }

    public function variables(): array
    {
        return [
            new VariableSupply('SUPPLIER_CODE', 'Supplier code'),
            new VariableSupply('CURRENCY', 'Currency code'),
        ];
    }

    public function dimensions(): array
    {
        return [];
    }

    public function routingAttributes(): array
    {
        return ['CURRENCY'];
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
            'SUPPLIER_CODE' => new NumberingValue('SUP012', 'sample'),
            'CURRENCY' => new NumberingValue('USD', 'sample'),
        ], ['CURRENCY' => 'USD']);
    }

    public function knownDimensionValues(): array
    {
        return [];
    }

    /** The context an issued order hands the numbering engine. */
    public static function contextFor(PurchaseOrder $order, CarbonImmutable $issuedAt, ?int $actorId = null, ?string $requestedSeries = null): NumberingContext
    {
        $supplier = SupplierAccount::query()->find($order->supplier_account_id);
        $currency = Currency::query()->find($order->currency_id);
        $values = [];
        if ($supplier !== null) {
            $values['SUPPLIER_CODE'] = new NumberingValue((string) $supplier->code, 'supplier_accounts#'.$supplier->id);
        }
        if ($currency !== null) {
            $values['CURRENCY'] = new NumberingValue((string) $currency->code, 'currencies#'.$currency->id);
        }

        return new NumberingContext(self::KEY, $issuedAt, $values, $currency !== null ? ['CURRENCY' => (string) $currency->code] : [], $order->id, $requestedSeries, $actorId);
    }
}
