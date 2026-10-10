<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Models\Company;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostStatus;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Pure estimate aggregation; absent cost kinds never count as known contributions. */
final class EstimateCostResolution
{
    /** @param list<CostResolution> $children
     * @param  array<string, mixed>  $configuration
     */
    public function aggregate(array $children, BigDecimal $quantity, int $uomId, string $amountBasis, CostReadSession $session, array $configuration = []): CostResolution
    {
        $complete = true;
        $known = null;
        $coverage = [];
        $diagnostics = [];
        $basis = [];
        foreach ($children as $index => $child) {
            $complete = $complete && $child->status === CostStatus::Costed;
            if ($child->knownCost !== null) {
                $known = ($known ?? BigDecimal::zero())->plus($child->knownCost);
            }
            array_push($coverage, ...$child->coverage);
            foreach ($child->diagnostics as $diagnostic) {
                $diagnostics[] = ['dependency' => $index, ...$diagnostic];
            }
            $basis[] = $child->toArray();
        }
        if ($configuration !== []) {
            $basis[] = $configuration;
        }
        if ($complete) {
            $known ??= BigDecimal::zero();
        }
        $status = $complete ? CostStatus::Costed : ($known === null ? CostStatus::Unknown : CostStatus::Partial);

        return $this->value($status, $complete ? $known : null, $known, $quantity, $uomId, $amountBasis,
            $session, $coverage, $diagnostics, $basis, 'ESTIMATE_ROLL_UP');
    }

    /** @param array<string, mixed> $evidence */
    public function configured(?BigDecimal $amount, BigDecimal $quantity, int $uomId, string $amountBasis, CostReadSession $session, array $evidence, ?string $reason = null): CostResolution
    {
        return $this->value($amount === null ? CostStatus::Unknown : CostStatus::Costed,
            $amount, $amount, $quantity, $uomId, $amountBasis, $session, [],
            $reason === null ? [] : [['code' => $reason]], [$evidence], 'CONFIGURED_ESTIMATE');
    }

    /** @param list<array<string, mixed>> $coverage
     * @param  list<array<string, mixed>>  $diagnostics
     * @param  list<array<string, mixed>>  $basis
     */
    private function value(CostStatus $status, ?BigDecimal $total, ?BigDecimal $known, BigDecimal $quantity, int $uomId, string $amountBasis, CostReadSession $session, array $coverage, array $diagnostics, array $basis, string $source): CostResolution
    {
        $total = $total?->toScale(4, RoundingMode::HALF_UP);
        $known = $known?->toScale(4, RoundingMode::HALF_UP);

        return new CostResolution($status, $total,
            $total !== null && ! $quantity->isZero() ? $total->dividedBy($quantity, 4, RoundingMode::HALF_UP) : null,
            $known, $amountBasis, $quantity, Company::findOrFail(app(CompanyContext::class)->id())->base_currency_id,
            $uomId, $coverage, $diagnostics, $source, 'ROLL_UP', ['type' => 'COMPANY'], ['type' => 'COMPANY'],
            null, $session->observedAt->toIso8601String(), $session->observedAt->toIso8601String(), $basis);
    }
}
