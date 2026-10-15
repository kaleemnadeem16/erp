<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Support\Casts\MoneyCast;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** FR0 — exact arithmetic, explicit settlement and honest certainty, with figures that can be checked by hand (ADR 0120). */
class FinancialKernelTest extends TestCase
{
    private function rule(int $places, RoundingMode $mode = RoundingMode::HALF_UP, int $currency = 1): SettlementRule
    {
        return new SettlementRule($currency, new CurrencyQuantum($places), $mode);
    }

    private function refused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail("Expected a refusal with {$code}.");
        } catch (DomainInvariantException $refusal) {
            $this->assertSame($code, $refusal->reasonCode);
        }
    }

    public function test_three_times_point_three_three_five_is_exact_before_settlement_then_one_oh_one(): void
    {
        $line = Arithmetic::settleLine('0.3350', '3', $this->rule(2));

        $this->assertSame('1.0050', (string) $line->computedDecimal());
        $this->assertSame(['numerator' => '201', 'denominator' => '200'], $line->computedFraction());
        $this->assertSame('1.0100', (string) $line->settled);
        $this->assertTrue($line->residual()->toBigDecimal()->isEqualTo('0.005'));
    }

    public function test_three_separately_settled_lines_sum_to_three_oh_three_not_the_raw_aggregate(): void
    {
        $rule = $this->rule(2);
        $lines = [Arithmetic::settleLine('0.3350', '3', $rule), Arithmetic::settleLine('0.3350', '3', $rule), Arithmetic::settleLine('0.3350', '3', $rule)];

        $this->assertSame('3.0300', (string) Arithmetic::sumSettled($lines));
        // The raw aggregate 3.0150 would settle to 3.02; the contract is the sum of settled lines.
        $this->assertSame('3.0200', (string) $rule->settle(BigRational::of('3.0150'))->settled);
    }

    /** @return array<string, array{int, string, string, string}> places, unit, quantity, expected settled */
    public static function currencyPrecisions(): array
    {
        return [
            'zero places' => [0, '0.3350', '3', '1.0000'],
            'two places' => [2, '0.3350', '3', '1.0100'],
            'three places (e.g. a dinar)' => [3, '0.3350', '3', '1.0050'],
            'four places' => [4, '0.33333', '3', ''],
        ];
    }

    #[DataProvider('currencyPrecisions')]
    public function test_currency_precision_from_zero_to_four_places(int $places, string $unit, string $quantity, string $expected): void
    {
        if ($places === 4) {
            // A five-place unit amount cannot be stored at all: refused, not rounded.
            $this->refused(fn () => Arithmetic::settleLine($unit, $quantity, $this->rule($places)), 'AMOUNT_NOT_REPRESENTABLE');

            return;
        }
        $this->assertSame($expected, (string) Arithmetic::settleLine($unit, $quantity, $this->rule($places))->settled);
    }

    public function test_four_place_currency_keeps_all_four_places_and_one_third_stays_exact_until_settled(): void
    {
        $line = Arithmetic::settleLine('1.0000', '0.333333333', $this->rule(4));
        $this->assertSame('0.3333', (string) $line->settled);
        $third = $this->rule(4)->settle(BigRational::nd(1, 3));
        $this->assertNull($third->computedDecimal());
        $this->assertSame(['numerator' => '1', 'denominator' => '3'], $third->computedFraction());
    }

    public function test_rounding_mode_is_explicit_and_changes_the_settled_value(): void
    {
        $this->assertSame('1.0100', (string) Arithmetic::settleLine('0.3350', '3', $this->rule(2, RoundingMode::HALF_UP))->settled);
        $this->assertSame('1.0000', (string) Arithmetic::settleLine('0.3350', '3', $this->rule(2, RoundingMode::HALF_EVEN))->settled);
        $this->assertSame('1.0000', (string) Arithmetic::settleLine('0.3350', '3', $this->rule(2, RoundingMode::DOWN))->settled);
        $this->assertSame('1.0100', (string) Arithmetic::settleLine('0.3350', '3', $this->rule(2, RoundingMode::CEILING))->settled);
    }

    public function test_unsupported_precision_and_rounding_mode_are_refused(): void
    {
        $this->refused(fn () => new CurrencyQuantum(5), 'UNSUPPORTED_CURRENCY_PRECISION');
        $this->refused(fn () => new CurrencyQuantum(-1), 'UNSUPPORTED_CURRENCY_PRECISION');
        $this->refused(fn () => $this->rule(2, RoundingMode::UNNECESSARY), 'UNSUPPORTED_ROUNDING_MODE');
    }

    #[DataProvider('currencyPlaces')]
    public function test_the_currency_quantum_is_exact_for_every_supported_place_count(int $places, string $unit): void
    {
        $quantum = new CurrencyQuantum($places);

        $this->assertSame($unit, (string) $quantum->unit());
        $this->assertSame($places, $quantum->unit()->getScale());
        $this->assertSame('1', (string) BigRational::of($quantum->unit())->multipliedBy(BigDecimal::of(10)->power($places))->simplified());
    }

    /** @return array<string, array{int, string}> */
    public static function currencyPlaces(): array
    {
        return ['zero (JPY)' => [0, '1'], 'two (USD)' => [2, '0.01'], 'three (KWD)' => [3, '0.001'], 'four (storage)' => [4, '0.0001']];
    }

    public function test_invalid_negative_and_overflowing_inputs_are_refused(): void
    {
        $this->refused(fn () => StorageAmount::of('abc'), 'INVALID_AMOUNT');
        $this->refused(fn () => StorageAmount::of('-0.0001'), 'NEGATIVE_AMOUNT');
        $this->refused(fn () => StorageAmount::of('1.00005'), 'AMOUNT_NOT_REPRESENTABLE');
        $this->refused(fn () => StorageAmount::of('1000000000000000'), 'AMOUNT_OVERFLOW');
        $this->refused(fn () => StorageAmount::ofRational(BigRational::nd(1, 3)), 'AMOUNT_NOT_REPRESENTABLE');
        $this->refused(fn () => Arithmetic::quantity('-1'), 'QUANTITY_NEGATIVE');
        $this->refused(fn () => Arithmetic::quantity('0.0000000001'), 'QUANTITY_NOT_REPRESENTABLE');
        $this->refused(fn () => Arithmetic::quantity('10000000000'), 'QUANTITY_OVERFLOW');
        $this->refused(fn () => Arithmetic::settleLine('999999999999999.9999', '2', $this->rule(2)), 'AMOUNT_OVERFLOW');
        $this->assertSame('999999999999999.9999', (string) StorageAmount::of('999999999999999.9999'));
        $this->assertSame('1.2000', (string) StorageAmount::of('1.20'));
    }

    public function test_sums_refuse_empty_mixed_currency_and_mixed_rules(): void
    {
        $this->refused(fn () => Arithmetic::sumSettled([]), 'EMPTY_SUM');
        $a = Arithmetic::settleLine('1', '1', $this->rule(2, currency: 1));
        $this->refused(fn () => Arithmetic::sumSettled([$a, Arithmetic::settleLine('1', '1', $this->rule(2, currency: 2))]), 'CURRENCY_MISMATCH');
        $this->refused(fn () => Arithmetic::sumSettled([$a, Arithmetic::settleLine('1', '1', $this->rule(3))]), 'SETTLEMENT_MISMATCH');
        $this->refused(fn () => Arithmetic::sumSettled([$a, Arithmetic::settleLine('1', '1', $this->rule(2, RoundingMode::DOWN))]), 'SETTLEMENT_MISMATCH');
    }

    public function test_a_known_zero_is_complete_and_unknown_is_never_zero(): void
    {
        $zero = FinancialResult::complete('0', 1);
        $this->assertSame(Certainty::Complete, $zero->certainty);
        $this->assertSame('0.0000', (string) $zero->amount);

        $unknown = FinancialResult::unknown([['code' => 'COST_UNKNOWN']]);
        $this->assertSame(Certainty::Unknown, $unknown->certainty);
        $this->assertNull($unknown->amount);
        $this->assertNull($unknown->knownAmount);
        $this->refused(fn () => FinancialResult::unknown([]), 'INCONSISTENT_RESULT');
        $this->refused(fn () => FinancialResult::partial('1', 1, []), 'INCONSISTENT_RESULT');
    }

    public function test_partial_keeps_only_its_known_part_and_never_becomes_complete_by_summing(): void
    {
        $partial = FinancialResult::partial('24', 1, [['code' => 'INSUFFICIENT_LAYERS']]);
        $this->assertNull($partial->amount);
        $this->assertSame('24.0000', (string) $partial->knownAmount);

        $sum = FinancialResult::sum([FinancialResult::complete('10', 1), $partial]);
        $this->assertSame(Certainty::Partial, $sum->certainty);
        $this->assertNull($sum->amount);
        $this->assertSame('34.0000', (string) $sum->knownAmount);

        $withUnknown = FinancialResult::sum([FinancialResult::complete('10', 1), FinancialResult::unknown([['code' => 'X']])]);
        $this->assertSame(Certainty::Partial, $withUnknown->certainty);
        $this->assertSame('10.0000', (string) $withUnknown->knownAmount);

        $allUnknown = FinancialResult::sum([FinancialResult::unknown([['code' => 'X']]), FinancialResult::unknown([['code' => 'Y']])]);
        $this->assertSame(Certainty::Unknown, $allUnknown->certainty);
        $this->assertCount(2, $allUnknown->reasons);

        $complete = FinancialResult::sum([FinancialResult::complete('10', 1), FinancialResult::complete('2.5', 1)]);
        $this->assertSame(Certainty::Complete, $complete->certainty);
        $this->assertSame('12.5000', (string) $complete->amount);

        $this->refused(fn () => FinancialResult::sum([]), 'EMPTY_SUM');
        $this->refused(fn () => FinancialResult::sum([FinancialResult::complete('1', 1), FinancialResult::complete('1', 2)]), 'CURRENCY_MISMATCH');
    }

    /**
     * Evidence for FR0 (B1): the shared MoneyCast reads decimal(19,4) through Money::of(..., UNNECESSARY), so a stored four-place amount in a currency with
     * fewer places throws instead of loading. It has no model callers today and is deliberately NOT changed here; this test pins the observation. If it
     * starts failing, the cast was changed or replaced under its own review — update the FR0 record, not the kernel.
     */
    public function test_the_shared_money_cast_cannot_load_a_four_place_amount_in_a_two_place_currency(): void
    {
        $cast = new MoneyCast;
        $model = new class extends Model {};

        $this->assertSame('0.335', (string) (new MoneyCast)->get($model, 'amount', '0.3350', ['currency' => 'KWD'])->getAmount());
        $this->expectException(RoundingNecessaryException::class);
        $cast->get($model, 'amount', '0.3350', ['currency' => 'USD']);
    }
}
