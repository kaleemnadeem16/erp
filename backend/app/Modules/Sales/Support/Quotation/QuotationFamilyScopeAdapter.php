<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use App\Support\Scope\ResolvedScope;
use App\Support\Scope\ResourceScopeAdapter;
use Illuminate\Database\Eloquent\Builder;

/** Exact-duty quotation-family scope, applied by QuotationAccess under its company fence. */
final class QuotationFamilyScopeAdapter implements ResourceScopeAdapter
{
    public const RESOURCE = 'quotation_family';

    public function supports(string $duty, string $scope): bool
    {
        if ($scope === AccessScopeLimit::OWN_OR_DELEGATED) {
            return in_array($duty, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true)
                && app(SalesDutyManifest::class)->isActive($duty);
        }

        return in_array($duty, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_ASSIGN, PermissionVocabulary::QUOTATION_ACCESS_DELEGATE, PermissionVocabulary::QUOTATION_ACCESS_REVOKE, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_WITHDRAW,
            PermissionVocabulary::QUOTATION_DISCOUNT, PermissionVocabulary::QUOTATION_PRICE_OVERRIDE, PermissionVocabulary::QUOTATION_COMMERCIAL_VIEW], true)
            && in_array($scope, ['ALL', AccessScopeLimit::OWN, AccessScopeLimit::MY_UNITS], true);
    }

    /**
     * @param  Builder<QuotationFamily>  $query
     * @return Builder<QuotationFamily>
     */
    public function apply(Builder $query, ResolvedScope $scope, int $actorId, ?string $duty = null, bool $allowNamedDelegation = true): Builder
    {
        $query->where('quotation_families.company_id', app(CompanyContext::class)->id());
        if ($scope->isAll()) {
            return $query;
        }
        $units = $scope->unitIds();
        $named = $allowNamedDelegation && $scope->allowsNamedDelegation() && in_array($duty, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true);
        if (! $scope->ownsRecords() && $units === [] && ! $named) {
            return $query->whereRaw('1 = 0');
        }

        // Own and active-unit paths are alternatives for this exact duty. Another mandatory
        // duty adds its own predicate; neither a creator nor the owner's memberships confer reach.
        return $query->where('quotation_families.access_baseline_state', '<>', 'UNRESOLVED')
            ->where(function (Builder $paths) use ($scope, $actorId, $units, $named, $duty): void {
                if ($scope->ownsRecords()) {
                    $paths->where('quotation_families.responsible_owner_id', $actorId);
                }
                if ($units !== []) {
                    $paths->orWhereIn('quotation_families.access_unit_id', $units);
                }
                if ($named) {
                    $paths->orWhereExists(app(EffectiveQuotationShares::class)->query($actorId, $duty));
                }
            });
    }
}
