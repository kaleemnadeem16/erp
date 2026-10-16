<?php

declare(strict_types=1);

namespace App\Modules\Sales\Numbering;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use Carbon\CarbonImmutable;

/**
 * The official number of an issued quotation revision (ADR 0123). Only the type is registered here, so an administrator can see and configure it;
 * nothing consumes the counter until the issue Action exists (QL3), and a draft, a family or an internal id never holds a number.
 */
final class QuotationNumbering implements NumberableDocumentType
{
    public const KEY = 'QUOTATION';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Quotation';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function becomesOfficial(): string
    {
        return 'when the quotation revision is issued';
    }

    public function variables(): array
    {
        return [];
    }

    public function dimensions(): array
    {
        return [];
    }

    public function routingAttributes(): array
    {
        return [];
    }

    public function defaultTemplate(): string
    {
        return 'QTN-{SEQ:5}';
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
        return 'sales.numbering.manage';
    }

    public function sampleContext(CarbonImmutable $at): NumberingContext
    {
        return new NumberingContext(self::KEY, $at, [], []);
    }

    public function knownDimensionValues(): array
    {
        return [];
    }
}
