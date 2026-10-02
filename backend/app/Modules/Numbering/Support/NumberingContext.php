<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use Carbon\CarbonImmutable;

/**
 * Everything the numbering engine learns about a document — and all it ever learns. The document supplies it at the moment it
 * becomes official; the engine knows nothing about suppliers, customers, warehouses or sales channels (ADR 0079 §3).
 */
final class NumberingContext
{
    /**
     * @param  array<string, NumberingValue>  $values  frozen values for the variables the document type supplies
     * @param  array<string, string>  $routing  attributes routing rules may test (ORDER_TYPE => IMPORT)
     */
    public function __construct(
        public readonly string $documentType,
        public readonly CarbonImmutable $issuedAt,
        public readonly array $values = [],
        public readonly array $routing = [],
        public readonly ?int $documentId = null,
        public readonly ?string $requestedSeries = null,
        public readonly ?int $allocatedBy = null,
    ) {}

    public function withValue(string $key, NumberingValue $value): self
    {
        return new self($this->documentType, $this->issuedAt, [...$this->values, $key => $value], $this->routing, $this->documentId, $this->requestedSeries, $this->allocatedBy);
    }
}
