<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\Scope\CompanyAccessFence;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotation-family eligibility for exact duties. The caller holds the enclosing transaction
 * through query execution and safe projection; a builder is not a durable authorisation.
 *
 * Shared by the adopted quotation entry points; named paths are limited to ordinary view/prepare.
 */
final class QuotationAccess
{
    public function __construct(
        private readonly CompanyAccessFence $fence,
        private readonly RecordScope $scope,
        private readonly QuotationFamilyScopeAdapter $adapter,
        private readonly SalesDutyManifest $manifest,
    ) {}

    /**
     * @param  list<string>  $duties
     * @return Builder<QuotationFamily>
     */
    public function queryFor(User $actor, array $duties, bool $allowNamedDelegation = true): Builder
    {
        $this->fence->lock();
        $current = ActiveActor::require($actor, 'access quotations');
        if ($duties === []) {
            throw new AuthorizationException('You are not permitted to access quotations.');
        }

        // Named sharing is purpose-bound to ordinary VIEW/PREPARE. A separate special-duty
        // operation must independently reach the family, even when that duty has an ALL scope.
        $allowNamedDelegation = $allowNamedDelegation && array_diff($duties, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]) === [];
        $query = QuotationFamily::query();
        foreach (array_unique($duties) as $duty) {
            if (! $this->manifest->isActive($duty) || ! $current->can($duty)) {
                throw new AuthorizationException('You are not permitted to access quotations.');
            }
            $resolved = $this->scope->resolveForResource($current, $duty, QuotationFamilyScopeAdapter::RESOURCE);
            if ($resolved->isNone()) {
                throw new AuthorizationException('You are not permitted to access quotations.');
            }
            $query = $this->adapter->apply($query, $resolved, (int) $current->id, $duty, $allowNamedDelegation);
        }

        return $query;
    }

    /** @param list<string> $duties */
    public function requireRevision(User $actor, int $revisionId, array $duties, bool $forUpdate = false, bool $allowNamedDelegation = true): QuotationRevision
    {
        $eligible = $this->queryFor($actor, $duties, $allowNamedDelegation)->select('quotation_families.id');
        $query = QuotationRevision::query()->whereKey($revisionId)->whereIn('family_id', $eligible);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
    }

    /** @param list<string> $duties */
    public function requireFamily(User $actor, int $familyId, array $duties, bool $forUpdate = false, bool $allowNamedDelegation = true): QuotationFamily
    {
        $query = $this->queryFor($actor, $duties, $allowNamedDelegation)->where('quotation_families.id', $familyId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'family_id');
    }
}
