<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

/**
 * How a quotation's manual SPOT rates are selected (ADR 0118). A value, not a table: the persisted, versioned and activated form belongs to the
 * configuration slice that waits for AU0/AU1, and it will build this same object. Until then the built-in default (exact date, maximum age zero, no
 * version) is the only policy a caller gets without supplying one explicitly, and the evidence says so.
 *
 * The route is always source→base and quotation→base through the company base currency, and the rate type is always SPOT; neither is a setting.
 */
final readonly class FxPolicy
{
    public function __construct(
        public ?int $version,
        public FxMode $mode,
        public int $maxAgeDays,
        public ?EffectivePeriod $period = null,
    ) {
        if ($maxAgeDays < 0) {
            throw FxRefusal::make(FxRefusal::FX_POLICY_INVALID, 'The maximum age cannot be negative.', 'max_age_days');
        }
        if ($mode === FxMode::ExactDate && $maxAgeDays !== 0) {
            throw FxRefusal::make(FxRefusal::FX_POLICY_INVALID, 'An exact-date policy has a maximum age of zero.', 'max_age_days');
        }
    }

    public static function builtInExactDate(): self
    {
        return new self(null, FxMode::ExactDate, 0);
    }

    public static function boundedPrior(int $version, int $maxAgeDays, ?EffectivePeriod $period = null): self
    {
        return new self($version, FxMode::BoundedPrior, $maxAgeDays, $period);
    }

    public function appliesOn(string $date): bool
    {
        return $this->period === null || $this->period->covers($date);
    }

    /**
     * The one policy version in force on a date. None, or more than one, refuses: adjacent half-open periods never both match, and an overlap is a
     * configuration error to surface, not a tie to break.
     *
     * @param  list<self>  $versions
     */
    public static function select(array $versions, string $date): self
    {
        $matching = array_values(array_filter($versions, static fn (self $policy): bool => $policy->appliesOn($date)));
        if ($matching === []) {
            throw FxRefusal::make(FxRefusal::FX_POLICY_MISSING, 'No FX policy version is in force on that date.', 'date');
        }
        if (count($matching) > 1) {
            throw FxRefusal::make(FxRefusal::FX_POLICY_AMBIGUOUS, 'More than one FX policy version is in force on that date.', 'date');
        }

        return $matching[0];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['version' => $this->version, 'source' => $this->version === null ? 'BUILT_IN_DEFAULT' : 'VERSIONED', 'mode' => $this->mode->value, 'max_age_days' => $this->maxAgeDays,
            'route' => 'COMPANY_BASE_BRIDGE', 'rate_type' => 'SPOT'];
    }
}
