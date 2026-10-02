<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Estimating\Support\InventoryAllocation;
use App\Modules\Estimating\Support\ResolvedStockMixControl;
use App\Modules\Estimating\Support\StockMix;
use App\Modules\Estimating\Support\StockMixApprovalSubject;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Estimating\Support\StockMixVerdict;
use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the company's control (ADR 0076) says about one hand-built mix. Pure of the viewer: it is judged the same whoever asks,
 * and the caller decides what to reveal.
 *
 * The comparison is on the *average unit cost* of what each draws, so a mix that covers only part of the requirement is not
 * mistaken for a cheap one. Only a costlier mix can trigger a stricter level. Anything that cannot be judged — no cost
 * evidence for the mix or the proposal, nothing covered, a proposal that costs nothing — is reported NOT_JUDGED, never
 * treated as within the limit. A stored approval level fails closed (BLOCKED) when approvals are no longer switched on.
 */
final class JudgeStockMix
{
    public function __construct(private readonly ApprovalModes $modes, private readonly ResolveApprovalPolicy $resolveApproval) {}

    public function __invoke(Item $item, ResolvedStockMixControl $control, StockMix $mix, InventoryAllocation $recommendation, ?string $note): StockMixVerdict
    {
        if (! $control->level->needsThreshold()) {
            return new StockMixVerdict(StockMixVerdict::NOT_CONTROLLED, null, null, null, []);
        }

        $threshold = BigDecimal::of((string) $control->thresholdPercent);
        if ($mix->estimatedCost === null || $recommendation->estimatedCost === null) {
            return new StockMixVerdict(StockMixVerdict::NOT_JUDGED, null, null, null, ['There is no defensible cost evidence for the mix or the proposal, so its cost could not be compared.']);
        }
        if ($mix->covered->isLessThanOrEqualTo(0) || $recommendation->covered->isLessThanOrEqualTo(0)) {
            return new StockMixVerdict(StockMixVerdict::NOT_JUDGED, null, null, null, ['Nothing is covered, so there is no unit cost to compare.']);
        }

        $mixUnit = $mix->estimatedCost->dividedBy($mix->covered, 9, RoundingMode::HALF_UP);
        $proposalUnit = $recommendation->estimatedCost->dividedBy($recommendation->covered, 9, RoundingMode::HALF_UP);
        if ($proposalUnit->isLessThanOrEqualTo(0)) {
            return $mixUnit->isGreaterThan(0)
                ? new StockMixVerdict(StockMixVerdict::NOT_JUDGED, null, null, null, ['The proposal costs nothing, so a percentage above it is undefined.'])
                : new StockMixVerdict(StockMixVerdict::WITHIN_LIMIT, BigDecimal::zero()->toScale(2), BigDecimal::zero()->toScale(4), null, []);
        }

        $percent = $mixUnit->minus($proposalUnit)->multipliedBy(100)->dividedBy($proposalUnit, 2, RoundingMode::HALF_UP);
        $extra = $mixUnit->minus($proposalUnit)->multipliedBy($mix->covered)->toScale(4, RoundingMode::HALF_UP);
        if ($percent->isLessThan($threshold)) {
            return new StockMixVerdict(StockMixVerdict::WITHIN_LIMIT, $percent, $extra, null, []);
        }

        if ($control->level === StockMixLevel::ReasonRequired) {
            return new StockMixVerdict(trim((string) $note) === '' ? StockMixVerdict::REASON_MISSING : StockMixVerdict::REASON_PROVIDED, $percent, $extra, null, []);
        }

        if ($this->modes->for(StockMixApprovalSubject::CODE) === ApprovalMode::None) {
            return new StockMixVerdict(StockMixVerdict::BLOCKED, $percent, $extra, null, ['This mix falls in an approval-required range, but approval is not switched on for hand-chosen stock, so it is blocked rather than downgraded.']);
        }

        return new StockMixVerdict(StockMixVerdict::APPROVAL_NEEDED, $percent, $extra, $this->approval($item, $percent, $extra), []);
    }

    /** @return array{policy: array{id: int, code: string, version_no: int, name: string}|null, steps: list<array{name: string, stage: int, required_approvals: int, uses_authority_limit: bool}>, note: string|null} */
    private function approval(Item $item, BigDecimal $percent, BigDecimal $extra): array
    {
        $routing = new ApprovalRouting(['item_id' => $item->id, 'category_id' => $item->category_id, 'cost_difference_percent' => (string) $percent], (string) $extra, [], []);
        $policy = ($this->resolveApproval)(StockMixApprovalSubject::CODE, $routing);
        if ($policy === null) {
            return ['policy' => null, 'steps' => [], 'note' => 'No approval policy applies to this mix, so it cannot be adopted until one is configured.'];
        }
        $steps = [];
        foreach ($policy->steps as $step) {
            $steps[] = ['name' => $step->name, 'stage' => $step->stage, 'required_approvals' => $step->required_approvals, 'uses_authority_limit' => (bool) $step->use_authority_limit];
        }

        return [
            'policy' => ['id' => $policy->id, 'code' => $policy->code, 'version_no' => $policy->version_no, 'name' => $policy->name],
            'steps' => $steps,
            'note' => null,
        ];
    }
}
