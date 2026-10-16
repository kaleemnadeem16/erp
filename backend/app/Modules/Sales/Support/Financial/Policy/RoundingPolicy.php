<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Modules\Sales\Models\RoundingPolicyVersion;
use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Fx\EffectivePeriod;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The Sales rounding and unit-price precision policy in force on a date (ADR 0120, 0144), as an immutable value a calculation can carry. It separates
 * the precisions that must never be merged: ENTRY (how many places a unit price may have — this policy), CALCULATION (exact until a settlement stage — FR0),
 * SETTLEMENT (the line net to the currency's own minor unit under this policy's rounding mode) and STORAGE (decimal(19,4), fixed).
 *
 * The quantum is not stored here: it is the currency's own decimal places, so a policy applies sensibly to a two-place, a three-place and a zero-place
 * currency alike. A company with no activated version has the built-in default — four places, HALF_UP, no version — and the evidence says so.
 */
final readonly class RoundingPolicy
{
    public const CALCULATION_VERSION = 'FR4.1';

    public const SETTLEMENT_BASIS = 'LINE_NET_TO_CURRENCY_QUANTUM';

    public const DOCUMENT_NET = 'SUM_OF_SETTLED_LINES';

    public const DEFAULT_UNIT_PRICE_SCALE = 4;

    public function __construct(
        public int $companyId,
        public ?int $versionId,
        public ?int $versionNo,
        public int $unitPriceScale,
        public SettlementRounding $rounding,
        public ?EffectivePeriod $period,
    ) {
        if ($unitPriceScale < 2 || $unitPriceScale > StorageAmount::SCALE) {
            throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_INVALID, 'A unit price may have two to four decimal places.', 'unit_price_scale');
        }
    }

    public static function builtInDefault(int $companyId): self
    {
        return new self($companyId, null, null, self::DEFAULT_UNIT_PRICE_SCALE, SettlementRounding::HalfUp, null);
    }

    public static function fromVersion(RoundingPolicyVersion $version): self
    {
        $period = $version->effective_from === null ? null : new EffectivePeriod($version->effective_from->format('Y-m-d'), $version->effective_end_exclusive?->format('Y-m-d'));

        return new self((int) $version->company_id, $version->id, $version->version_no, $version->unit_price_scale, SettlementRounding::from($version->rounding_mode), $period);
    }

    public function isBuiltIn(): bool
    {
        return $this->versionNo === null;
    }

    public function appliesOn(string $date): bool
    {
        return $this->period === null || $this->period->covers($date);
    }

    /**
     * A unit price a person or API may enter under this policy, as storage money ("0.3400"). More places than the policy allows are refused with their
     * own code — never rounded, because a changed price is a commercial decision — and so is anything storage could not hold.
     */
    public function acceptUnitPrice(string|BigDecimal $price, string $field = 'unit_price'): BigDecimal
    {
        // Plain decimal digits only: no exponent form, padding or float-like text is an exact price.
        if (is_string($price) && preg_match('/^-?\d+(\.\d+)?$/', $price) !== 1) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_AMOUNT, 'The amount is not an exact decimal number.', $field);
        }
        try {
            $value = BigDecimal::of($price);
        } catch (MathException) {
            throw FinancialRefusal::make(FinancialRefusal::INVALID_AMOUNT, 'The amount is not an exact decimal number.', $field);
        }
        if ($value->strippedOfTrailingZeros()->getScale() > $this->unitPriceScale) {
            throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::UNIT_PRICE_PRECISION_EXCEEDED, "A unit price may have at most {$this->unitPriceScale} decimal places.", $field);
        }

        return StorageAmount::of($value, $field);
    }

    public function conforms(string|BigDecimal $price): bool
    {
        try {
            $this->acceptUnitPrice($price);

            return true;
        } catch (DomainInvariantException) {
            return false;
        }
    }

    /** The explicit settlement decision for a currency: its own minor unit and this policy's rounding mode. */
    public function settlementRule(Currency $currency): SettlementRule
    {
        return SettlementRule::forCurrency($currency, $this->rounding->mode());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'company_id' => $this->companyId,
            'source' => $this->isBuiltIn() ? 'BUILT_IN_DEFAULT' : 'VERSIONED',
            'version_id' => $this->versionId,
            'version_no' => $this->versionNo,
            'unit_price_scale' => $this->unitPriceScale,
            'rounding_mode' => $this->rounding->value,
            'settlement_basis' => self::SETTLEMENT_BASIS,
            'document_net' => self::DOCUMENT_NET,
            'effective_from' => $this->period?->from,
            'effective_end_exclusive' => $this->period?->endExclusive,
        ];
    }

    /**
     * Identifies what the policy *does*: company, entry precision, rounding mode, basis and calculation version. Not the version number, row id or
     * dates, so a successor with identical rules is not "changed" and closing a version never alters the fingerprint of the rules it carried.
     */
    public function fingerprint(): string
    {
        return OperationIntent::hash('fr4.rounding_policy', [
            'calculation_version' => self::CALCULATION_VERSION,
            'company_id' => $this->companyId,
            'unit_price_scale' => $this->unitPriceScale,
            'rounding_mode' => $this->rounding->value,
            'settlement_basis' => self::SETTLEMENT_BASIS,
            'document_net' => self::DOCUMENT_NET,
        ]);
    }
}
