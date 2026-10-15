<?php

namespace Tests\Unit\Support\Quantity;

use App\Exceptions\DomainInvariantException;
use App\Support\Quantity\QuantityContext;
use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** PX0 — the shared quantity contract (ADR 0142): exact representability, increments and conversions, refused rather than rounded. */
class QuantityContractTest extends TestCase
{
    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            $this->assertNotNull($refusal->reasonCode);

            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_contexts_carry_their_fixed_scales(): void
    {
        $this->assertSame(4, QuantityContract::stockLedger()->scale);
        $this->assertSame(6, QuantityContract::assemblyTechnical()->scale);
        $this->assertSame(9, QuantityContract::conversionEvidence()->scale);
        $this->assertSame(QuantityContext::StockLedger, QuantityContract::stockLedger()->context);
        $this->assertNull(QuantityContext::CommercialLine->fixedScale());
        $this->assertSame(4, QuantityContract::commercialLine(4)->scale);
        $this->assertSame(0, QuantityContract::commercialLine(0)->scale);
    }

    public function test_the_width_follows_decimal_19(): void
    {
        $this->assertSame('999999999999999.9999', (string) QuantityContract::stockLedger()->maximum);
        $this->assertSame('9999999999999.999999', (string) QuantityContract::assemblyTechnical()->maximum);
        $this->assertSame('9999999999.999999999', (string) QuantityContract::conversionEvidence()->maximum);
    }

    /** @return array<string, array{string, string, string}> contract, input, stored */
    public static function accepted(): array
    {
        return [
            'ledger whole' => ['stock', '5', '5.0000'],
            'ledger litre' => ['stock', '1.275', '1.2750'],
            'ledger smallest step' => ['stock', '0.0001', '0.0001'],
            'ledger trailing zeros are not digits' => ['stock', '1.27500000', '1.2750'],
            'ledger maximum' => ['stock', '999999999999999.9999', '999999999999999.9999'],
            'technical six places' => ['tech', '0.000125', '0.000125'],
            'technical smallest step' => ['tech', '0.000001', '0.000001'],
            'evidence nine places' => ['evidence', '6.666666667', '6.666666667'],
            'line whole units' => ['line0', '12', '12'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_representable_quantities_are_returned_at_the_destination_scale(string $contract, string $input, string $stored): void
    {
        $this->assertSame($stored, (string) $this->contract($contract)->represent($input));
    }

    private function contract(string $name): QuantityContract
    {
        return match ($name) {
            'stock' => QuantityContract::stockLedger(),
            'tech' => QuantityContract::assemblyTechnical(),
            'evidence' => QuantityContract::conversionEvidence(),
            'line0' => QuantityContract::commercialLine(0),
            default => throw new \InvalidArgumentException($name),
        };
    }

    /** @return array<string, array{string, string, string}> contract, input, code */
    public static function refused(): array
    {
        return [
            'ledger five places' => ['stock', '0.00001', 'QUANTITY_NOT_REPRESENTABLE'],
            'ledger a repeating third' => ['stock', '0.3333333333', 'QUANTITY_NOT_REPRESENTABLE'],
            'ledger overflow' => ['stock', '1000000000000000', 'QUANTITY_OVERFLOW'],
            'ledger zero' => ['stock', '0', 'QUANTITY_ZERO'],
            'ledger zero with places' => ['stock', '0.0000', 'QUANTITY_ZERO'],
            'ledger negative' => ['stock', '-1', 'QUANTITY_NEGATIVE'],
            'ledger text' => ['stock', 'abc', 'QUANTITY_INVALID'],
            'ledger exponent form' => ['stock', '1e3', 'QUANTITY_INVALID'],
            'ledger empty' => ['stock', '', 'QUANTITY_INVALID'],
            'ledger leading dot' => ['stock', '.5', 'QUANTITY_INVALID'],
            'ledger padded' => ['stock', ' 1', 'QUANTITY_INVALID'],
            'technical seven places' => ['tech', '0.0000001', 'QUANTITY_NOT_REPRESENTABLE'],
            'technical overflow' => ['tech', '10000000000000', 'QUANTITY_OVERFLOW'],
            'evidence ten places' => ['evidence', '0.0000000001', 'QUANTITY_NOT_REPRESENTABLE'],
            'whole-unit line with a fraction' => ['line0', '1.5', 'QUANTITY_NOT_REPRESENTABLE'],
        ];
    }

    #[DataProvider('refused')]
    public function test_unrepresentable_quantities_are_refused_not_rounded(string $contract, string $input, string $code): void
    {
        $this->assertSame($code, $this->refusal(fn () => $this->contract($contract)->represent($input)));
    }

    public function test_sign_policy_is_explicit(): void
    {
        $ledger = QuantityContract::stockLedger();
        $this->assertSame('0.0000', (string) $ledger->represent('0', QuantitySign::NonNegative));
        $this->assertSame('-2.5000', (string) $ledger->represent('-2.5', QuantitySign::Any));
        $this->assertSame('QUANTITY_NEGATIVE', $this->refusal(fn () => $ledger->represent('-0.0001', QuantitySign::NonNegative)));
        $this->assertSame('QUANTITY_OVERFLOW', $this->refusal(fn () => $ledger->represent('-1000000000000000', QuantitySign::Any)));
    }

    public function test_only_exact_inputs_are_accepted_and_floats_never_are(): void
    {
        $ledger = QuantityContract::stockLedger();
        $this->assertSame('3.0000', (string) $ledger->represent(3));
        $this->assertSame('1.2500', (string) $ledger->represent(BigDecimal::of('1.25')));
        // Decoded JSON can hand over a float, null, bool or array: a controlled refusal, never a binary float or a TypeError.
        foreach ([0.1, 1.0, null, true, [], new \stdClass] as $value) {
            $this->assertSame('QUANTITY_INVALID', $this->refusal(fn () => $ledger->represent($value)));
        }
        $this->assertSame('FACTOR_INVALID', $this->refusal(fn () => QuantityContract::factor(2.5)));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptIncrement(0.5)));
        $this->assertSame('QUANTITY_INVALID', $this->refusal(fn () => $ledger->convert(1.5, '2')));
    }

    public function test_an_unsupported_scale_is_a_loud_contract_error(): void
    {
        $this->assertSame('CONTRACT_INVALID', $this->refusal(fn () => QuantityContract::commercialLine(10)));
        $this->assertSame('CONTRACT_INVALID', $this->refusal(fn () => QuantityContract::commercialLine(-1)));
    }

    public function test_increments_must_be_whole_multiples(): void
    {
        $halves = QuantityContract::stockLedger('0.5');
        $this->assertSame('1.5000', (string) $halves->represent('1.5'));
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $halves->represent('1.25')));

        $litres = QuantityContract::stockLedger('0.001');
        $this->assertSame('1.2750', (string) $litres->represent('1.275'));
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $litres->represent('1.2755')));

        $whole = QuantityContract::stockLedger('1');
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $whole->represent('2.5')));
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $whole->increment === null ? '' : $this->refusal(fn () => $whole->represent('0.0001')));
    }

    public function test_increment_definitions_are_validated_against_the_destination(): void
    {
        $ledger = QuantityContract::stockLedger();
        $this->assertSame('0.0010', (string) $ledger->acceptIncrement('0.001'));
        $this->assertSame('1.0000', (string) $ledger->acceptIncrement('1'));
        $this->assertSame('0.0001', (string) $ledger->acceptIncrement('0.0001'));
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->acceptIncrement('0.00001')));
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->acceptIncrement('0.000000001')));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptIncrement('0')));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptIncrement('-1')));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptIncrement('abc')));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptIncrement('1000000000000000')));
        // The same nine-place increment is meaningful only where the destination holds it.
        $this->assertSame('0.000000001', (string) QuantityContract::conversionEvidence()->acceptIncrement('0.000000001'));
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->refusal(fn () => QuantityContract::stockLedger('0.00001')));
        $this->assertSame('0.000125', (string) QuantityContract::assemblyTechnical()->acceptIncrement('0.000125'));
    }

    public function test_conversion_is_exact_or_refused(): void
    {
        $ledger = QuantityContract::stockLedger();

        $cartons = $ledger->convert('3', '12.5');
        $this->assertSame('37.5000', (string) $cartons->result);
        $this->assertTrue($cartons->exact->isEqualTo($cartons->result));
        $this->assertSame('12.500000000', (string) $cartons->factor);

        $litres = $ledger->convert('1.275', '1');
        $this->assertSame('1.2750', (string) $litres->result);

        // 7 × 6.666666667 = 46.666666669 — nine places, not storable at four.
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->convert('7', '6.666666667')));
        // A fractional quantity that lands exactly is fine: 0.25 × 2 = 0.5.
        $this->assertSame('0.5000', (string) $ledger->convert('0.25', '2')->result);
        // A fractional carton that does not: 0.3 × 6.666666667 = 2.0000000001.
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->convert('0.3', '6.666666667')));
        // A small factor: 1 gram = 0.001 kg is fine, 1 milligram = 0.000001 kg is not at four places.
        $this->assertSame('0.0010', (string) $ledger->convert('1', '0.001')->result);
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->convert('1', '0.000001')));
        // The same milligram is exact in a six-place technical destination.
        $this->assertSame('0.000001', (string) QuantityContract::assemblyTechnical()->convert('1', '0.000001')->result);
    }

    public function test_conversion_respects_overflow_sign_and_increment(): void
    {
        $ledger = QuantityContract::stockLedger();
        $this->assertSame('QUANTITY_OVERFLOW', $this->refusal(fn () => $ledger->convert('999999999999999', '2')));
        $this->assertSame('QUANTITY_ZERO', $this->refusal(fn () => $ledger->convert('0', '2')));
        $this->assertSame('QUANTITY_NEGATIVE', $this->refusal(fn () => $ledger->convert('-1', '2')));
        $this->assertSame('-2.0000', (string) $ledger->convert('-1', '2', QuantitySign::Any)->result);
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => QuantityContract::stockLedger('0.5')->convert('3', '0.25')));
        $this->assertSame('1.5000', (string) QuantityContract::stockLedger('0.5')->convert('3', '0.5')->result);
    }

    public function test_conversion_factors_are_evidence_with_their_own_limits(): void
    {
        $this->assertSame('6.666666667', (string) QuantityContract::factor('6.666666667'));
        $this->assertSame('1.000000000', (string) QuantityContract::factor('1'));
        $this->assertSame('FACTOR_NOT_REPRESENTABLE', $this->refusal(fn () => QuantityContract::factor('6.6666666667')));
        $this->assertSame('FACTOR_NOT_REPRESENTABLE', $this->refusal(fn () => QuantityContract::factor('10000000000')));
        $this->assertSame('FACTOR_INVALID', $this->refusal(fn () => QuantityContract::factor('0')));
        $this->assertSame('FACTOR_INVALID', $this->refusal(fn () => QuantityContract::factor('-2')));
        $this->assertSame('FACTOR_INVALID', $this->refusal(fn () => QuantityContract::factor('two')));
        $this->assertSame('FACTOR_NOT_REPRESENTABLE', $this->refusal(fn () => QuantityContract::stockLedger()->convert('1', '0.0000000001')));
    }

    public function test_a_transaction_unit_increment_is_checked_through_its_factor(): void
    {
        $ledger = QuantityContract::stockLedger();
        // Half a carton of 3 litres is 1.5 litres.
        $this->assertSame('1.5000', (string) $ledger->acceptTransactionIncrement('0.5', '3'));
        // 0.0001 of a carton of 0.3 litres is 0.00003 litres: below the ledger.
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->refusal(fn () => $ledger->acceptTransactionIncrement('0.0001', '0.3')));
        $this->assertSame('INCREMENT_INVALID', $this->refusal(fn () => $ledger->acceptTransactionIncrement('0', '3')));
        $this->assertSame('FACTOR_INVALID', $this->refusal(fn () => $ledger->acceptTransactionIncrement('0.5', '0')));
        // With a stock increment of 0.5 L, a quarter-carton step of 0.75 L is not a whole multiple.
        $stock = QuantityContract::stockLedger('0.5');
        $this->assertSame('1.5000', (string) $stock->acceptTransactionIncrement('0.5', '3'));
        $this->assertSame('INCREMENT_INCOMPATIBLE', $this->refusal(fn () => $stock->acceptTransactionIncrement('0.25', '3')));
    }

    public function test_representability_can_be_asked_without_an_exception(): void
    {
        $ledger = QuantityContract::stockLedger();
        $this->assertTrue($ledger->isRepresentable('46.6667'));
        $this->assertTrue($ledger->isRepresentable('0'));
        $this->assertFalse($ledger->isRepresentable('46.666666669'));
        $this->assertFalse($ledger->isRepresentable('x'));
        $this->assertFalse($ledger->isRepresentable('1000000000000000'));
    }

    public function test_with_increment_returns_a_new_contract_and_keeps_the_original(): void
    {
        $base = QuantityContract::stockLedger();
        $halves = $base->withIncrement('0.5');
        $this->assertNull($base->increment);
        $this->assertSame('0.5000', (string) $halves->increment);
        $this->assertSame(4, $halves->scale);
        $this->assertNull($halves->withIncrement(null)->increment);
    }
}
