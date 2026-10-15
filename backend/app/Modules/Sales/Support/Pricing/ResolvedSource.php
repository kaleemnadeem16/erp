<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\FinancialResult;

/**
 * What a source adapter proved about the thing being sold, for the requested quantity: its identity and label, and its cost as honest certainty in the
 * cost's own currency (the company base currency for stock). Cost is internal evidence; it is never a price input.
 */
final readonly class ResolvedSource
{
    /** @param  array<string, mixed>  $evidence  copied source facts (cost method, observation time, scope) for later snapshotting */
    public function __construct(
        public PricingSourceKind $kind,
        public ?int $sourceId,
        public string $label,
        public ?int $uomId,
        public FinancialResult $cost,
        public array $evidence = [],
    ) {}
}
