<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\Scope\ResolvedScope;
use App\Support\Scope\ResourceScopeAdapter;
use Illuminate\Database\Eloquent\Builder;

/** QA1b contract only; no existing quotation Action or endpoint uses this disabled adapter yet. */
final class QuotationFamilyScopeAdapter implements ResourceScopeAdapter
{
    public const RESOURCE = 'quotation_family';

    public function supports(string $duty, string $scope): bool
    {
        return in_array($duty, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_ASSIGN], true)
            && in_array($scope, ['ALL', AccessScopeLimit::OWN], true);
    }

    /** @param Builder<QuotationFamily> $query @return Builder<QuotationFamily> */
    public function apply(Builder $query, ResolvedScope $scope, int $actorId): Builder
    {
        if ($scope->isAll()) {
            return $query;
        }
        if ($scope->ownsRecords()) {
            return $query->where('responsible_owner_id', $actorId)->where('access_baseline_state', '<>', 'UNRESOLVED');
        }

        return $query->whereRaw('1 = 0');
    }
}
