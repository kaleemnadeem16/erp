<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostStatus;
use Brick\Math\BigDecimal;

/**
 * An amount together with how certain it is. COMPLETE carries the amount; PARTIAL carries only the known contribution (a lower bound, never a total);
 * UNKNOWN carries nothing and must say why. The type cannot be built in any other shape, so unknown can never read as zero and partial can never read as
 * complete. Internal value: whether a reader may see it (cost, margin) is decided by the guarded caller.
 */
final readonly class FinancialResult
{
    /**
     * @param  list<array{code: string, detail: string|null}>  $reasons
     */
    private function __construct(
        public Certainty $certainty,
        public ?BigDecimal $amount,
        public ?BigDecimal $knownAmount,
        public ?int $currencyId,
        public array $reasons,
    ) {
        $consistent = match ($certainty) {
            Certainty::Complete => $amount !== null && $knownAmount !== null && $amount->isEqualTo($knownAmount) && $currencyId !== null,
            Certainty::Partial => $amount === null && $knownAmount !== null && $currencyId !== null && $reasons !== [],
            Certainty::Unknown => $amount === null && $knownAmount === null && $reasons !== [],
        };
        if (! $consistent) {
            throw FinancialRefusal::make(FinancialRefusal::INCONSISTENT_RESULT, 'A financial result must match its certainty: complete has an amount, partial a known part and a reason, unknown only a reason.');
        }
    }

    public static function complete(string|BigDecimal $amount, int $currencyId): self
    {
        $value = StorageAmount::of($amount);

        return new self(Certainty::Complete, $value, $value, $currencyId, []);
    }

    /** @param  list<array{code: string, detail?: string|null}>  $reasons */
    public static function partial(string|BigDecimal $known, int $currencyId, array $reasons): self
    {
        return new self(Certainty::Partial, null, StorageAmount::of($known, 'known_amount'), $currencyId, self::reasons($reasons));
    }

    /** @param  list<array{code: string, detail?: string|null}>  $reasons */
    public static function unknown(array $reasons): self
    {
        return new self(Certainty::Unknown, null, null, null, self::reasons($reasons));
    }

    /** The cost contract (0110) read as financial certainty: COSTED is complete, PARTIAL keeps only its known part, UNKNOWN stays unknown. */
    public static function fromCost(CostResolution $cost): self
    {
        $reasons = array_map(
            static fn (array $diagnostic): array => ['code' => (string) ($diagnostic['code'] ?? $diagnostic['reason'] ?? 'COST_DIAGNOSTIC'), 'detail' => null],
            $cost->diagnostics,
        );

        return match ($cost->status) {
            CostStatus::Costed => self::complete($cost->totalCost ?? throw FinancialRefusal::make(FinancialRefusal::INCONSISTENT_RESULT, 'A costed result has no total.'), self::currency($cost)),
            CostStatus::Partial => self::partial($cost->knownCost ?? throw FinancialRefusal::make(FinancialRefusal::INCONSISTENT_RESULT, 'A partial cost has no known part.'), self::currency($cost), $reasons === [] ? [['code' => 'COST_PARTIAL']] : $reasons),
            CostStatus::Unknown => self::unknown($reasons === [] ? [['code' => 'COST_UNKNOWN']] : $reasons),
        };
    }

    /**
     * Total of several results in one currency. All complete ⇒ complete; some known and some not ⇒ partial with the known sum; nothing known ⇒ unknown.
     * An empty list is refused: no contributions is not a known zero.
     *
     * @param  list<self>  $parts
     */
    public static function sum(array $parts): self
    {
        if ($parts === []) {
            throw FinancialRefusal::make(FinancialRefusal::EMPTY_SUM, 'There is nothing to sum; an empty set is not a known zero.');
        }
        $known = null;
        $currency = null;
        $complete = true;
        $reasons = [];
        foreach ($parts as $part) {
            $complete = $complete && $part->certainty === Certainty::Complete;
            array_push($reasons, ...$part->reasons);
            if ($part->knownAmount === null) {
                continue;
            }
            if ($currency !== null && $currency !== $part->currencyId) {
                throw FinancialRefusal::make(FinancialRefusal::CURRENCY_MISMATCH, 'Results in different currencies cannot be summed without an FX bridge.');
            }
            $currency = $part->currencyId;
            $known = ($known ?? BigDecimal::zero())->plus($part->knownAmount);
        }
        if ($known === null) {
            return self::unknown($reasons);
        }

        return $complete ? self::complete($known, (int) $currency) : self::partial($known, (int) $currency, $reasons === [] ? [['code' => 'PARTIAL_CONTRIBUTION']] : $reasons);
    }

    /** @param  list<array{code: string, detail?: string|null}>  $reasons
     * @return list<array{code: string, detail: string|null}>
     */
    private static function reasons(array $reasons): array
    {
        if ($reasons === []) {
            throw FinancialRefusal::make(FinancialRefusal::INCONSISTENT_RESULT, 'A partial or unknown result must say why.');
        }

        return array_map(static fn (array $reason): array => ['code' => $reason['code'], 'detail' => $reason['detail'] ?? null], $reasons);
    }

    private static function currency(CostResolution $cost): int
    {
        return $cost->currencyId ?? throw FinancialRefusal::make(FinancialRefusal::INCONSISTENT_RESULT, 'A cost with an amount has no currency.');
    }
}
