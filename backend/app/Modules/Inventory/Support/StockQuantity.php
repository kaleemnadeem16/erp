<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Brick\Math\BigDecimal;

/**
 * Inventory's one door to the shared quantity contract for stock-ledger quantities (ADR 0142). Each method returns an exact four-place quantity or
 * refuses with a stable reason code (a 422), so no package, count or posting path can fail with a raw arithmetic exception or let the database round
 * an over-precise value on insert. Nothing here rounds.
 */
final class StockQuantity
{
    /** A quantity a person or caller supplies for a stock movement, count or package line: greater than zero, at most four places. */
    public static function positive(mixed $value, string $field = 'quantity'): BigDecimal
    {
        return QuantityContract::stockLedger()->represent($value, QuantitySign::Positive, $field);
    }

    /** As {@see positive()}, zero allowed (a counted balance). */
    public static function nonNegative(mixed $value, string $field = 'quantity'): BigDecimal
    {
        return QuantityContract::stockLedger()->represent($value, QuantitySign::NonNegative, $field);
    }

    /**
     * A quantity whose sign the caller judges itself (a movement posting keeps its own "must be greater than zero" message): only the precision, width and
     * format are checked here.
     */
    public static function signed(mixed $value, string $field = 'quantity'): BigDecimal
    {
        return QuantityContract::stockLedger()->represent($value, QuantitySign::Any, $field);
    }

    /** What a package definition says it holds, at stock precision; a definition finer than the ledger is refused, never truncated. */
    public static function packageContent(mixed $content, string $field = 'content_quantity'): BigDecimal
    {
        return self::positive($content, $field);
    }

    /** The stock quantity of `$count` whole sealed packages: exact by construction, and refused if it would not fit the ledger. */
    public static function sealed(mixed $content, int $count, string $field = 'quantity'): BigDecimal
    {
        return QuantityContract::stockLedger()->convert($count, self::packageContent($content, $field), QuantitySign::Positive, $field)->result;
    }
}
