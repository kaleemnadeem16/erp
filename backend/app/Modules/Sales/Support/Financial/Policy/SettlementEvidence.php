<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

use App\Modules\Sales\Support\Financial\SettledAmount;

/**
 * The frozen explanation of one settled line: the exact computed value (as a fraction, never a rounded decimal), the settled amount, the signed residual
 * between them, the currency's quantum and the rounding mode applied. The residual is evidence, never absorbed or hidden (ADR 0120).
 */
final class SettlementEvidence
{
    /** @return array<string, mixed> */
    public static function of(SettledAmount $line): array
    {
        $residual = $line->residual()->simplified();

        return [
            'computed' => $line->computedFraction(),
            'settled' => (string) $line->settled,
            'residual' => ['numerator' => (string) $residual->getNumerator(), 'denominator' => (string) $residual->getDenominator()],
            'currency_id' => $line->rule->currencyId,
            'quantum_places' => $line->rule->quantum->decimalPlaces,
            'rounding_mode' => self::modeName($line),
        ];
    }

    private static function modeName(SettledAmount $line): string
    {
        foreach (SettlementRounding::cases() as $rounding) {
            if ($rounding->mode() === $line->rule->mode) {
                return $rounding->value;
            }
        }

        return $line->rule->mode->name;
    }
}
