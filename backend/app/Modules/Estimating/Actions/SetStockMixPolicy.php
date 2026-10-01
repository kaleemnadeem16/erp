<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Estimating\Models\StockMixPolicy;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\StockMixApprovalSubject;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Records how tightly a scope controls hand-chosen stock (ADR 0076). A governed change: an actor and a written reason, the
 * previous row kept as history, only later previews affected. A null level records "no preference here" (inherit). The
 * stricter levels need a positive threshold, and the approval level is only available while the company has approvals
 * switched on for it. Recording what is already in force writes nothing.
 *
 * The action accepts every scope; which scopes a company may set today is the request layer's rule (company only), so
 * widening it later is not a change here.
 */
final class SetStockMixPolicy
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly ApprovalModes $approvals) {}

    public function __invoke(MaterialSelectionScope $scope, ?int $scopeId, ?StockMixLevel $level, ?string $thresholdPercent, string $reason, int $actorId): StockMixPolicy
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainInvariantException('Changing how hand-chosen stock is controlled requires a reason.', 'reason');
        }
        if (($scope === MaterialSelectionScope::Company) !== ($scopeId === null)) {
            throw new DomainInvariantException('Only the company scope has no scope id, and every other scope needs one.', 'scope_id');
        }
        $threshold = $this->threshold($level, $thresholdPercent);
        if ($level === StockMixLevel::ApprovalRequired && $this->approvals->for(StockMixApprovalSubject::CODE) === ApprovalMode::None) {
            throw new DomainInvariantException('Approval can only be required once approvals are switched on for hand-chosen stock (Settings → Approvals).', 'level');
        }

        return DB::transaction(function () use ($scope, $scopeId, $level, $threshold, $reason, $actorId): StockMixPolicy {
            Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());
            $this->assertScopeExists($scope, $scopeId);

            $active = StockMixPolicy::query()
                ->where('scope_type', $scope)
                ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId))
                ->where('status', MaterialSelectionPolicyStatus::Active)
                ->first();

            if ($active !== null && $active->level === $level && $this->same($active->threshold_percent, $threshold)) {
                return $active;
            }
            if ($active === null && $level === null) {
                throw new DomainInvariantException('There is nothing stored here to clear.', 'level');
            }

            $now = now();
            $active?->update(['status' => MaterialSelectionPolicyStatus::Superseded, 'effective_to' => $now]);

            return StockMixPolicy::query()->create([
                'scope_type' => $scope, 'scope_id' => $scopeId, 'level' => $level, 'threshold_percent' => $threshold,
                'reason' => $reason, 'status' => MaterialSelectionPolicyStatus::Active, 'effective_from' => $now, 'changed_by' => $actorId,
            ])->refresh();
        });
    }

    private function threshold(?StockMixLevel $level, ?string $threshold): ?string
    {
        if ($level === null || ! $level->needsThreshold()) {
            if ($threshold !== null) {
                throw new DomainInvariantException('A percentage only applies to the levels that act on one.', 'threshold_percent');
            }

            return null;
        }
        if ($threshold === null || BigDecimal::of($threshold)->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('This level needs a percentage above zero.', 'threshold_percent');
        }

        return (string) BigDecimal::of($threshold)->toScale(4);
    }

    private function same(?string $stored, ?string $new): bool
    {
        return ($stored === null) === ($new === null) && ($stored === null || BigDecimal::of($stored)->isEqualTo($new));
    }

    private function assertScopeExists(MaterialSelectionScope $scope, ?int $scopeId): void
    {
        $exists = match ($scope) {
            MaterialSelectionScope::Company => true,
            MaterialSelectionScope::ItemCategory => Category::query()->whereKey($scopeId)->exists(),
            MaterialSelectionScope::Item => Item::query()->whereKey($scopeId)->exists(),
        };
        if (! $exists) {
            throw new DomainInvariantException('The selected item or category does not exist.', 'scope_id');
        }
    }
}
