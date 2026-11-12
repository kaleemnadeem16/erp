<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Numbering;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use Carbon\CarbonImmutable;

/**
 * A Job is numbered when it is received from its released source: nothing about it that a number could depend on changes afterwards. The manage permission names a
 * Jobs numbering duty that is not registered (no Jobs duty is ACTIVE), so nobody can manage the series yet; the default series is still created on first use.
 */
final class JobNumbering implements NumberableDocumentType
{
    public const KEY = 'JOB';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Job';
    }

    public function module(): string
    {
        return 'jobs';
    }

    public function becomesOfficial(): string
    {
        return 'when the released service is received';
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
        return 'JOB-{SEQ:5}';
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
        return 'job.numbering.manage';
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
