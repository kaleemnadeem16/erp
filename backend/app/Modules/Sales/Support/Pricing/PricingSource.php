<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\FinancialResult;

/**
 * What is being sold, declared by kind and identity. Catalogue sources are referenced by id only — the adapter loads and verifies them in the current
 * company. Manual scope carries its own description, measure and an explicit cost certainty (default UNKNOWN); it never creates or text-matches an Item.
 */
final readonly class PricingSource
{
    private function __construct(
        public PricingSourceKind $kind,
        public ?int $id,
        public ?string $description,
        public ?string $measure,
        public ?FinancialResult $manualCost,
    ) {}

    public static function directItem(int $itemId): self
    {
        return new self(PricingSourceKind::DirectItem, $itemId, null, null, null);
    }

    public static function serviceItem(int $itemId): self
    {
        return new self(PricingSourceKind::ServiceItem, $itemId, null, null, null);
    }

    public static function assemblyVersion(int $assemblyVersionId): self
    {
        return new self(PricingSourceKind::AssemblyWork, $assemblyVersionId, null, null, null);
    }

    public static function manual(string $description, string $measure, ?FinancialResult $cost = null): self
    {
        if (trim($description) === '' || trim($measure) === '') {
            throw PricingRefusal::make(PricingRefusal::INVALID_INPUT, 'Manual scope needs a description and a commercial measure.', 'source');
        }

        return new self(PricingSourceKind::Manual, null, trim($description), trim($measure), $cost);
    }
}
