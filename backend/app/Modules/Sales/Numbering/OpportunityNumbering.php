<?php

declare(strict_types=1);

namespace App\Modules\Sales\Numbering;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use Carbon\CarbonImmutable;

/** A opportunity is numbered when it is recorded: nothing about it that a number could depend on changes afterwards. */
final class OpportunityNumbering implements NumberableDocumentType
{
    public const KEY = 'OPPORTUNITY';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Opportunity';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function becomesOfficial(): string
    {
        return 'when the opportunity is created';
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
        return 'OPP-{SEQ:5}';
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

    public static function contextFor(int $documentId, CarbonImmutable $at, ?int $actorId = null): NumberingContext
    {
        return new NumberingContext(self::KEY, $at, [], [], $documentId, null, $actorId);
    }
}
