<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * The approval-routing facts of one material plan evaluation, collected server-side while the preview runs and handed only to the module that adopts the plan
 * (Phase 4 quotation adoption, ADR 0054/0076/0122). A cost-blind adopter still needs the real internal-cost figures to route an approval correctly, but the preview must not
 * return them to that person; this is the narrow side channel. It carries amounts and technical source identities only (never a unit cost), is never serialised (`__serialize` throws and nothing
 * here is JSON-encodable), and is `@internal` to Estimating's previews and the one adopting Action (`PlanRoutingCollectorHasOneCallerTest`).
 *
 * A figure that could not be established is null, never zero.
 *
 * @internal
 */
final class PlanRoutingCollector
{
    /** @var array<int, string|null> */
    private array $variance = [];

    /** @var array<int, array{percent: string|null, extra: string|null}> */
    private array $mix = [];

    /** @var array<int, array<string, mixed>> */
    private array $sources = [];

    public function recordVariance(int $itemId, ?string $absoluteCostDelta): void
    {
        $this->variance[$itemId] = $absoluteCostDelta;
    }

    public function recordMix(int $itemId, ?string $percent, ?string $extraCost): void
    {
        $this->mix[$itemId] = ['percent' => $percent, 'extra' => $extraCost];
    }

    /**
     * The exact technical source a component resolved to — the application-rate row (with the quantity it carried) and every formulation version used, top level and nested — so the
     * adopting module can pin it. Identities and the rate's quantity only; nothing here is cost.
     *
     * @param  array<string, mixed>  $source
     */
    public function recordSource(int $componentId, array $source): void
    {
        $this->sources[$componentId] = $source;
    }

    /** @return array<int, array<string, mixed>> component id => source, in component order */
    public function sources(): array
    {
        ksort($this->sources);

        return $this->sources;
    }

    public function varianceAmount(int $itemId): ?string
    {
        return $this->variance[$itemId] ?? null;
    }

    /** @return array{percent: string|null, extra: string|null}|null */
    public function mixFigures(int $itemId): ?array
    {
        return $this->mix[$itemId] ?? null;
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Routing figures are internal and are never serialised.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['routing' => 'internal'];
    }
}
