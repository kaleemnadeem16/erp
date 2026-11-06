<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Quotation\Approval\QuotationContentFingerprint;
use App\Support\CompanyContext;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** @internal Read-only assessment. The owning guarded Action proves record/field access and holds the common fence. */
final class AssessDiscountAuthority
{
    /** @return array<string, mixed> */
    public function assess(QuotationRevision $revision): array
    {
        $configuration = app(DiscountConfiguration::class);
        $security = DB::table('company_access_generations')->where('company_id', $revision->company_id)->first();
        $family = $revision->family()->firstOrFail();
        $result = ['version' => 'QDA.1', 'generation' => (int) DB::table('sales_authority_heads')->where('company_id', app(CompanyContext::class)->id())->value('generation'),
            'company_timezone' => app(CurrentCompany::class)->timezone(),
            'participant_epochs' => app(AuthorityParticipants::class)->epochs($revision),
            'capability_generation' => (int) ($security->capability_generation ?? 0), 'organisation_generation' => (int) ($security->organisation_generation ?? 0),
            'family_access_generation' => $family->access_generation, 'family_ownership_generation' => $family->ownership_generation, 'access_unit_id' => $family->access_unit_id,
            'content_fingerprint' => QuotationContentFingerprint::of($revision), 'policy_id' => null, 'assignments' => [], 'measure' => null,
            'required_band_id' => null, 'required_rank' => null, 'expiry_calendar_days' => null, 'result' => 'UNEVALUABLE', 'reasons' => []];
        try {
            if (app(CurrentCompany::class)->baseCurrencyId() === null) {
                throw AuthorityRefusal::make('AUTHORITY_BASE_CURRENCY_REQUIRED', 'Set the company base currency before evaluating monetary authority.');
            }
            $policy = $configuration->policy($revision);
            $result['policy_id'] = $policy->id;
            $result['expiry_calendar_days'] = $policy->expiry_calendar_days;
            $measure = app(MeasureQuotationConcessions::class)->measure($revision);
            $result['measure'] = $measure;
            $bands = SalesDiscountBand::query()->where('policy_id', $policy->id)->orderBy('rank')->get();
            $required = $bands->first(fn (SalesDiscountBand $b): bool => app(DiscountBandCoverage::class)->covers($b, $policy, $measure, true));
            $result['required_band_id'] = $required?->id;
            $result['required_rank'] = $required?->rank;
            $routine = ! $measure['override'] && ! $measure['undeclared_reduction'];
            if ($measure['override']) {
                $result['reasons'][] = 'FINAL_NET_OVERRIDE_REQUIRES_APPROVAL';
            }
            if ($measure['undeclared_reduction']) {
                $result['reasons'][] = 'UNDECLARED_REDUCTION_REQUIRES_APPROVAL';
            }
            foreach ($measure['actors'] as $id) {
                $person = User::query()->where('company_id', $revision->company_id)->find($id);
                $assignment = $person === null ? null : $configuration->assignment($person, $revision, $policy, 'ROUTINE');
                $band = $assignment === null ? null : $bands->firstWhere('id', $assignment->band_id);
                $result['assignments'][(string) $id] = $assignment?->id;
                if ($band === null || ! app(DiscountBandCoverage::class)->covers($band, $policy, $measure)) {
                    $routine = false;
                    $result['reasons'][] = $assignment === null ? 'EMPLOYEE_AUTHORITY_MISSING' : 'EMPLOYEE_AUTHORITY_EXCEEDED';
                }
            }
            $concessions = QuotationContentFingerprint::concessions($revision) !== 'NONE' || $measure['undeclared_reduction'];
            $result['result'] = ! $concessions ? 'NOT_APPLICABLE' : ($routine ? 'ROUTINE' : 'APPROVAL_REQUIRED');
            if ($result['result'] === 'APPROVAL_REQUIRED' && $required === null) {
                $result['reasons'][] = 'APPROVER_BAND_MISSING';
            }
        } catch (DomainInvariantException $e) {
            $result['reasons'][] = $e->reasonCode ?? 'AUTHORITY_EVIDENCE_UNVERIFIED';
        }
        $result['fingerprint'] = OperationIntent::hash('sales.authority_evaluation.v1', $result);

        return $result;
    }
}
