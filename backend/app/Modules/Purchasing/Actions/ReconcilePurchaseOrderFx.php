<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Support\CompanyContext;
use App\Support\RateType;
use App\Support\Scope\DecisionActor;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Reconciles the FX rate a person typed on a purchase order with the company's governed exchange rate (issue #90, audit F3, ADR 0165), because that rate
 * decides the order's base amount, hence its approval band and the value its receipts put into stock.
 *
 *  - The governed rate is the latest stored rate of the order's rate type for order currency to base, dated on or before the order's rate date and within
 *    `purchasing.fx.max_rate_age_days`. The base currency itself is always 1. A company with no base currency has nothing to reconcile with and refuses.
 *  - Within `purchasing.fx.tolerance_percent` of it, the typed rate is accepted as typed (the governed rate and its date are recorded beside it).
 *  - Otherwise it is accepted only as a declared FIXED rate with a written reason, entered by someone holding `purchasing.purchase-order.fx-override`;
 *    who and why are recorded. A client cannot make a rate "contractual" by saying so: without the capability it is refused.
 *  - Anything else is refused. The governed rate is not substituted silently, so the buyer sees the difference and decides.
 *
 * Returns the evidence columns to store with the order. Checks no permission other than the override capability; the calling Action authorises the actor.
 */
final class ReconcilePurchaseOrderFx
{
    public const OVERRIDE_PERMISSION = 'purchasing.purchase-order.fx-override';

    public function __construct(private readonly CompanyContext $company) {}

    /**
     * @param  array<string, mixed>  $header  the order's effective currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_policy and fx_override_reason
     * @return array{fx_governed_rate: ?string, fx_governed_rate_date: ?string, fx_override_reason: ?string, fx_override_by: ?int}
     */
    public function __invoke(array $header, User $actor): array
    {
        $baseId = Company::query()->find($this->company->id())?->base_currency_id;
        if ($baseId === null) {
            throw new DomainInvariantException('The company has no base currency, so the exchange rate cannot be checked.', 'fx_rate', 'FX_BASE_CURRENCY_MISSING');
        }
        try {
            $typed = BigDecimal::of((string) $header['fx_rate']);
        } catch (MathException) {
            throw new DomainInvariantException('The exchange rate is not a number.', 'fx_rate', 'FX_RATE_INVALID');
        }
        [$governed, $governedDate] = $this->governed((int) $header['currency_id'], (int) $baseId, (string) $header['fx_rate_type'], Carbon::parse((string) $header['fx_rate_date'])->toDateString());

        if ($governed !== null && $this->withinTolerance($typed, $governed)) {
            return $this->evidence($governed, $governedDate);
        }

        $policy = $header['fx_policy'] ?? PurchaseOrderFxPolicy::Floating;
        $policy = $policy instanceof PurchaseOrderFxPolicy ? $policy : PurchaseOrderFxPolicy::from((string) $policy);
        $reason = trim((string) ($header['fx_override_reason'] ?? ''));
        $departure = $governed === null ? 'No governed exchange rate is available to check it against' : "It differs from the governed rate {$governed->toScale(9)} by more than ".config('purchasing.fx.tolerance_percent').'%';

        // Only a fixed rate needs the override duty; it is decided from the stored actor (the calling Action runs inside its transaction, behind the access barrier).
        if ($policy !== PurchaseOrderFxPolicy::Fixed || ! DecisionActor::fresh($actor, 'fix an exchange rate', 'fx_rate')->hasPermissionTo(self::OVERRIDE_PERMISSION)) {
            throw new DomainInvariantException("This exchange rate is not accepted. {$departure}. Use the governed rate; a contractually fixed rate must be entered by a person permitted to fix rates.", 'fx_rate', $governed === null ? 'FX_RATE_UNGOVERNED' : 'FX_RATE_OUTSIDE_TOLERANCE');
        }
        if (mb_strlen($reason) < (int) config('purchasing.fx.min_override_reason_length', 10)) {
            throw new DomainInvariantException('A fixed exchange rate that differs from the governed rate needs the contract or forward reference as the reason.', 'fx_override_reason', 'FX_OVERRIDE_REASON_REQUIRED');
        }

        return $this->evidence($governed, $governedDate, $reason, $actor->id);
    }

    /** @return array{0: ?BigDecimal, 1: ?string} */
    private function governed(int $currencyId, int $baseId, string $rateType, string $rateDate): array
    {
        if ($currencyId === $baseId) {
            return [BigDecimal::one(), $rateDate];
        }
        // A purchase uses the SPOT rate at its date (multi-currency.md, rate selection); only AVERAGE and CLOSING select another. The order form's
        // free-text label ("MANUAL") is evidence of how it was typed, not a different rate type.
        $type = RateType::tryFrom(strtoupper($rateType)) ?? RateType::Spot;
        $row = ExchangeRate::query()->where('from_currency_id', $currencyId)->where('to_currency_id', $baseId)->where('rate_type', $type->value)
            ->whereDate('rate_date', '<=', $rateDate)->whereDate('rate_date', '>=', Carbon::parse($rateDate)->subDays((int) config('purchasing.fx.max_rate_age_days', 7))->toDateString())
            ->orderByDesc('rate_date')->first();
        if ($row === null || ! BigDecimal::of($row->rate)->isPositive()) {
            return [null, null];
        }

        return [BigDecimal::of($row->rate), $row->rate_date->format('Y-m-d')];
    }

    private function withinTolerance(BigDecimal $typed, BigDecimal $governed): bool
    {
        $deviationPercent = $typed->minus($governed)->abs()->multipliedBy(100)->dividedBy($governed, 9, RoundingMode::HALF_UP);

        return $deviationPercent->isLessThanOrEqualTo(BigDecimal::of((string) config('purchasing.fx.tolerance_percent', '0.5')));
    }

    /** @return array{fx_governed_rate: ?string, fx_governed_rate_date: ?string, fx_override_reason: ?string, fx_override_by: ?int} */
    private function evidence(?BigDecimal $governed, ?string $date, ?string $reason = null, ?int $by = null): array
    {
        return ['fx_governed_rate' => $governed === null ? null : (string) $governed->toScale(9), 'fx_governed_rate_date' => $date, 'fx_override_reason' => $reason, 'fx_override_by' => $by];
    }
}
