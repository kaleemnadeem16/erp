<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostStatus;
use Brick\Math\BigDecimal;
use Closure;
use JsonSerializable;
use LogicException;

/**
 * What Estimating can say about the cost of ONE Assembly version for ONE work quantity, for an authorised workflow only. The identity, the certainty and a fixed safe
 * reason code are public; the figures are not: they sit behind `amounts()`, which needs the explicit `CostEvidenceWorkflow`, and are never in `jsonSerialize`,
 * `__debugInfo` or a serialisation (which throws). Missing, partial or refused evidence is UNKNOWN or PARTIAL with a reason — never zero, never complete.
 */
final class AssemblyCostEvidence implements JsonSerializable
{
    public const ASSEMBLY_VERSION_NOT_FOUND = 'ASSEMBLY_VERSION_NOT_FOUND';

    public const ASSEMBLY_VERSION_NOT_ACTIVE = 'ASSEMBLY_VERSION_NOT_ACTIVE';

    public const WORK_QUANTITY_INVALID = 'WORK_QUANTITY_INVALID';

    public const COST_EVIDENCE_UNAVAILABLE = 'COST_EVIDENCE_UNAVAILABLE';

    public const COST_PARTIAL = 'COST_PARTIAL';

    public const COST_UNKNOWN = 'COST_UNKNOWN';

    /** @param  Closure(): array{total: BigDecimal|null, known: BigDecimal|null, currency: int|null}|null  $reveal */
    private function __construct(
        public readonly ?int $assemblyVersionId,
        public readonly CostEvidenceCertainty $certainty,
        public readonly ?string $reasonCode,
        private readonly ?Closure $reveal,
    ) {}

    public static function from(int $assemblyVersionId, CostResolution $cost): self
    {
        $figures = ['total' => $cost->totalCost, 'known' => $cost->knownCost, 'currency' => $cost->currencyId];

        return match ($cost->status) {
            CostStatus::Costed => new self($assemblyVersionId, CostEvidenceCertainty::Complete, null, fn (): array => $figures),
            CostStatus::Partial => new self($assemblyVersionId, CostEvidenceCertainty::Partial, self::COST_PARTIAL, fn (): array => $figures),
            CostStatus::Unknown => new self($assemblyVersionId, CostEvidenceCertainty::Unknown, self::COST_UNKNOWN, null),
        };
    }

    public static function unavailable(?int $assemblyVersionId, string $reasonCode): self
    {
        return new self($assemblyVersionId, CostEvidenceCertainty::Unknown, $reasonCode, null);
    }

    /** The figures, or null when there are none (UNKNOWN). Only a named, authorised workflow may ask. */
    public function amounts(CostEvidenceWorkflow $workflow): ?ConfidentialCostAmounts
    {
        return $this->reveal === null ? null : new ConfidentialCostAmounts($this->reveal);
    }

    /** @return array{assembly_version_id: int|null, certainty: string, reason_code: string|null} */
    public function jsonSerialize(): array
    {
        return ['assembly_version_id' => $this->assemblyVersionId, 'certainty' => $this->certainty->value, 'reason_code' => $this->reasonCode];
    }

    /** @return array{assembly_version_id: int|null, certainty: string, reason_code: string|null} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Cost evidence is not serialisable.');
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Cost evidence is not serialisable.');
    }
}
