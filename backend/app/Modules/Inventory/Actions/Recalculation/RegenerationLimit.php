<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRegenerationReview;
use App\Modules\Inventory\Models\RecalculationRequest;

/** Internal to recalculation Actions. Counts immutable history, never resets on manual resumption. */
final class RegenerationLimit
{
    // ADR 0040 leaves the finite product parameter provisional: three per review interval.
    public const INTERVAL = 3;

    public function invalidations(RecalculationRequest $request): int
    {
        return $request->impactVersions()->where('is_stale', true)
            ->whereHas('approvals', fn ($q) => $q->where('decision', 'APPROVED'))->count();
    }

    /** @return array{version: RecalculationImpactVersion, count: int, escalation: string}|null */
    public function pending(RecalculationRequest $request): ?array
    {
        $count = $this->invalidations($request);
        $lastReview = RecalculationRegenerationReview::query()
            ->whereIn('impact_version_id', $request->impactVersions()->select('id'))->max('invalidation_count');
        if ($count - (int) $lastReview < self::INTERVAL) {
            return null;
        }
        $version = $request->impactVersions()->where('is_stale', true)->orderByDesc('version_no')->firstOrFail();

        return ['version' => $version, 'count' => $count, 'escalation' => $lastReview === null ? 'PRIORITY_REVIEW' : 'HIGHER_SEVERITY'];
    }
}
