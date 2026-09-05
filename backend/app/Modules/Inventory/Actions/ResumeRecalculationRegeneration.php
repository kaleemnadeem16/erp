<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Recalculation\RecalculationImpactBuilder;
use App\Modules\Inventory\Actions\Recalculation\RegenerationLimit;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRegenerationReview;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Manual coordination after a cap hit; records the review and requires fresh approvals. */
final class ResumeRecalculationRegeneration
{
    public function __construct(private readonly RegenerationLimit $limit, private readonly RecalculationImpactBuilder $impacts) {}

    public function __invoke(RecalculationImpactVersion $pausedVersion, string $notes, User $actor): RecalculationImpactVersion
    {
        return DB::transaction(function () use ($pausedVersion, $notes, $actor): RecalculationImpactVersion {
            $company = Company::query()->lockForUpdate()->findOrFail(app(CompanyContext::class)->id());
            $request = RecalculationRequest::query()->lockForUpdate()->findOrFail($pausedVersion->recalculation_request_id);
            if (trim($notes) === '' || $actor->id === $request->requested_by || ! $actor->can('inventory.recalculation.review-regeneration')) {
                throw new DomainInvariantException('Independent regeneration-review authority and notes are required.', 'reviewed_by');
            }
            $previous = RecalculationRegenerationReview::query()->where('impact_version_id', $pausedVersion->id)->first();
            if ($previous !== null) {
                if ($previous->reviewed_by !== $actor->id || $previous->notes !== trim($notes)) {
                    throw new DomainInvariantException('This pause already has a different manual review.', 'impact_version_id');
                }

                return $request->impactVersions()->where('version_no', $pausedVersion->version_no + 1)->firstOrFail();
            }
            $pause = $this->limit->pending($request);
            if ($pause === null || $pause['version']->id !== $pausedVersion->id) {
                throw new DomainInvariantException('This version is not awaiting regeneration review.', 'impact_version_id');
            }
            if ($pause['escalation'] === 'HIGHER_SEVERITY' && ! $actor->can('inventory.recalculation.approve-high-risk')) {
                throw new DomainInvariantException('Repeated cap hits require higher-risk authority.', 'reviewed_by');
            }
            $attributes = $this->impacts->attributes($request, $company);
            RecalculationRegenerationReview::query()->create([
                'impact_version_id' => $pausedVersion->id, 'reviewed_by' => $actor->id,
                'invalidation_count' => $pause['count'], 'escalation' => $pause['escalation'],
                'notes' => trim($notes), 'reviewed_at' => now(),
            ]);

            return $this->impacts->append($request, $attributes);
        });
    }
}
