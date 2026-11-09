<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/** @internal Sales-only interpretation of evaluated authority for the shared engine. */
final class QuotationAuthorityApproval
{
    public function enabled(): bool
    {
        return app(DiscountConfiguration::class)->mode() === 'ENFORCE';
    }

    /** @return array<string, mixed> */
    public function assessment(QuotationRevision $revision): array
    {
        $assessment = app(AssessDiscountAuthority::class)->assess($revision);
        if ($assessment['measure'] === null || $assessment['required_band_id'] === null) {
            if (config('sales.discount_authority_hide_missing_evidence', false)) {
                throw (new ModelNotFoundException)->setModel(QuotationRevision::class);
            }
            throw AuthorityRefusal::make('AUTHORITY_APPROVAL_EVIDENCE_MISSING', 'Approval needs current governed reference, FX and authority evidence.');
        }

        return $assessment;
    }

    public function mayDecide(User $person, QuotationRevision $revision): bool
    {
        $configuration = app(DiscountConfiguration::class);
        $assessment = $this->assessment($revision);
        $policy = $configuration->policy($revision);
        $assignment = $configuration->assignment($person, $revision, $policy, 'APPROVE');
        $band = $assignment === null ? null : SalesDiscountBand::query()->find($assignment->band_id);

        return SalesAccess::may($person, PermissionVocabulary::AUTHORITY_EVIDENCE_VIEW)
            && $band !== null && ($band->rank === $assessment['required_rank'] || ($policy->allow_higher_approver && $band->rank > $assessment['required_rank']))
            && app(DiscountBandCoverage::class)->covers($band, $policy, $assessment['measure'], true);
    }

    public function deadline(QuotationRevision $revision): string
    {
        $zone = app(CurrentCompany::class)->timezone();
        $days = min(14, (int) $this->assessment($revision)['expiry_calendar_days']);
        $deadline = now($zone)->addDays($days);
        if ($revision->valid_through_local_date !== null) {
            $validity = Carbon::parse($revision->valid_through_local_date->format('Y-m-d'), $zone)->addDay()->startOfDay();
            $deadline = $deadline->min($validity);
        }

        return $deadline->utc()->toIso8601String();
    }

    public function expired(ApprovalRequest $request): bool
    {
        $deadline = $request->facts['evidence']['authority_expires_at'] ?? null;

        return ($this->enabled() || $deadline !== null) && ($deadline === null || now()->greaterThanOrEqualTo(Carbon::parse($deadline)));
    }

    public function assertUnexpired(ApprovalRequest $request): void
    {
        if ($this->expired($request)) {
            throw AuthorityRefusal::make('AUTHORITY_APPROVAL_EXPIRED', 'This approval has expired. Submit the current quotation for review again.');
        }
    }
}
