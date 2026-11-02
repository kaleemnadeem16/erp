<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Support\Scope\CompanyAccessFence;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotation-family eligibility for exact duties. The caller holds the enclosing transaction
 * through query execution and safe projection; a builder is not a durable authorisation.
 *
 * @internal Not wired to quotation entry points until the whole QA3 adoption is complete.
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
    public function queryFor(User $actor, array $duties): Builder
    {
        $this->fence->lock();
        $current = ActiveActor::require($actor, 'access quotations');
        if ($duties === []) {
            throw new AuthorizationException('You are not permitted to access quotations.');
        }

        $query = QuotationFamily::query();
        foreach (array_unique($duties) as $duty) {
            if (! $this->manifest->isActive($duty) || ! $current->can($duty)) {
                throw new AuthorizationException('You are not permitted to access quotations.');
            }
            $resolved = $this->scope->resolveForResource($current, $duty, QuotationFamilyScopeAdapter::RESOURCE);
            if ($resolved->isNone()) {
                throw new AuthorizationException('You are not permitted to access quotations.');
            }
            $query = $this->adapter->apply($query, $resolved, (int) $current->id);
        }

        return $query;
    }

    /** @param list<string> $duties */
    public function requireFamily(User $actor, int $familyId, array $duties): QuotationFamily
    {
        return $this->queryFor($actor, $duties)->where('quotation_families.id', $familyId)->firstOrFail();
    }
}
