<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Numbering\Support\VariableSupply;
use Carbon\CarbonImmutable;

/**
 * A document type invented by the tests, to prove the framework needs nothing from the document but this contract: it has no
 * table, no model, and the engine has never heard of branches or sales channels.
 */
final class FakeNumberableDocument implements NumberableDocumentType
{
    public function __construct(
        private readonly Continuity $continuity = Continuity::AllowGaps,
        private readonly bool $locked = false,
        private readonly string $template = '{SEQ:4}',
    ) {}

    public function key(): string
    {
        return 'SALES_INVOICE';
    }

    public function label(): string
    {
        return 'Sales invoice';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function becomesOfficial(): string
    {
        return 'when it is finalised';
    }

    public function variables(): array
    {
        return [new VariableSupply('BRANCH', 'Branch code'), new VariableSupply('CUSTOMER_CODE', 'Customer code')];
    }

    public function dimensions(): array
    {
        return ['BRANCH'];
    }

    public function routingAttributes(): array
    {
        return ['SALE_TYPE', 'BRANCH'];
    }

    public function defaultTemplate(): string
    {
        return $this->template;
    }

    public function defaultScope(): CounterScope
    {
        return CounterScope::company();
    }

    public function defaultContinuity(): Continuity
    {
        return $this->continuity;
    }

    public function continuityLocked(): bool
    {
        return $this->locked;
    }

    public function managePermission(): string
    {
        return 'sales.numbering.manage';
    }

    public function sampleContext(CarbonImmutable $at): NumberingContext
    {
        return new NumberingContext($this->key(), $at, ['BRANCH' => new NumberingValue('LHR'), 'CUSTOMER_CODE' => new NumberingValue('CUS001')], ['SALE_TYPE' => 'DOMESTIC', 'BRANCH' => 'LHR']);
    }

    public function knownDimensionValues(): array
    {
        return ['BRANCH' => ['LHR', 'KHI']];
    }

    /** @param  array<string, string>  $routing */
    public static function context(string $at, string $branch = 'LHR', array $routing = [], ?string $series = null, ?int $actor = null): NumberingContext
    {
        return new NumberingContext('SALES_INVOICE', CarbonImmutable::parse($at), [
            'BRANCH' => new NumberingValue($branch, 'branches#'.($branch === 'LHR' ? 1 : 2)),
            'CUSTOMER_CODE' => new NumberingValue('CUS001', 'customers#7'),
        ], $routing + ['SALE_TYPE' => 'DOMESTIC', 'BRANCH' => $branch], 500, $series, $actor);
    }
}
