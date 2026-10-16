<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\RoundingPolicyVersion;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyRefusal;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyStatus;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates the next DRAFT version of the company's Sales rounding and unit-price precision policy. It changes nothing in force: a draft governs no
 * calculation until it is activated. Guarded by `sales.financial.configure` — a STAGED duty: this Action exists, but until the duty is activated in the
 * manifest (with a reviewed holder recipe) `SalesAccess` refuses every caller, so the operation stays disabled. No route calls it.
 */
final class CreateRoundingPolicyDraft
{
    public function __construct(private readonly CompanyContext $company) {}

    public function __invoke(User $actor, int $unitPriceScale, SettlementRounding $rounding, string $reason): RoundingPolicyVersion
    {
        SalesAccess::require($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, 'configure the Sales rounding policy');
        if ((int) $actor->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The actor is outside the active company.', 'actor', 'ACTOR_OUTSIDE_COMPANY');
        }
        if ($unitPriceScale < 2 || $unitPriceScale > StorageAmount::SCALE) {
            throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_INVALID, 'A unit price may have two to four decimal places.', 'unit_price_scale');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_INVALID, 'A reason of up to 1000 characters is required for every policy version.', 'reason');
        }

        return DB::transaction(function () use ($actor, $unitPriceScale, $rounding, $reason): RoundingPolicyVersion {
            // The company row is the fence every Sales financial policy writer takes, so version numbers cannot race.
            Company::query()->whereKey($this->company->id())->lockForUpdate()->firstOrFail();

            return RoundingPolicyVersion::create([
                'version_no' => (int) RoundingPolicyVersion::query()->max('version_no') + 1,
                'unit_price_scale' => $unitPriceScale,
                'rounding_mode' => $rounding->value,
                'status' => RoundingPolicyStatus::Draft->value,
                'reason' => $reason,
                'changed_by' => $actor->id,
            ])->refresh();
        });
    }
}
