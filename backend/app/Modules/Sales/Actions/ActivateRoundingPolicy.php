<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\RoundingPolicyVersion;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyRefusal;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyStatus;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Puts a DRAFT into force from an effective date, closing the version it replaces at that same date so the two periods meet without overlapping
 * (half-open, ADR 0118). It applies to new work only: the date may not be in the past (no backdating to change what was already priced) and must fall
 * after the version being replaced. Everything runs under the company fence, so two activations cannot interleave. Same STAGED guard as the draft Action.
 */
final class ActivateRoundingPolicy
{
    public function __construct(private readonly CompanyContext $company) {}

    public function __invoke(RoundingPolicyVersion $policy, User $actor, string $effectiveFrom): RoundingPolicyVersion
    {
        SalesAccess::require($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, 'configure the Sales rounding policy');
        if ((int) $actor->company_id !== $this->company->id() || (int) $policy->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The policy is outside the active company.', 'policy', 'ACTOR_OUTSIDE_COMPANY');
        }
        FinancialDate::assertCalendarDate($effectiveFrom, 'effective_from');

        return DB::transaction(function () use ($policy, $actor, $effectiveFrom): RoundingPolicyVersion {
            $company = Company::query()->whereKey($this->company->id())->lockForUpdate()->firstOrFail();
            $draft = RoundingPolicyVersion::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== RoundingPolicyStatus::Draft->value) {
                throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_NOT_DRAFT, 'Only a draft policy version can be activated.', 'status');
            }
            $today = FinancialDate::forInstant(now(), $company->timezone)->date;
            if ($effectiveFrom < $today) {
                throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_EFFECTIVE_DATE_INVALID, 'A policy cannot take effect in the past; it applies to new work only.', 'effective_from');
            }
            $head = RoundingPolicyVersion::query()->where('status', RoundingPolicyStatus::Active->value)->lockForUpdate()->first();
            if ($head !== null && $effectiveFrom <= $head->effective_from->format('Y-m-d')) {
                throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_EFFECTIVE_DATE_INVALID, 'The policy must take effect after the version it replaces.', 'effective_from');
            }

            // The predecessor is closed first, so there are never two open-ended versions at any instant.
            $head?->update(['status' => RoundingPolicyStatus::Superseded->value, 'effective_end_exclusive' => $effectiveFrom]);
            $draft->update([
                'status' => RoundingPolicyStatus::Active->value,
                'effective_from' => $effectiveFrom,
                'previous_version_id' => $head?->id,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);

            return $draft->refresh();
        });
    }
}
