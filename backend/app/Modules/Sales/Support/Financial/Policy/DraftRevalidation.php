<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

/**
 * The outcome of checking a draft against the policy now in force: is it stale, and which of its unit prices no longer fit the entry precision. It names
 * problems and corrects nothing — a changed price is a commercial decision for a person, so the draft is marked for review, never rewritten (ADR 0144 §4).
 */
final readonly class DraftRevalidation
{
    public const POLICY_CHANGED = 'ROUNDING_POLICY_CHANGED';

    public const UNIT_PRICE_NO_LONGER_FITS = 'UNIT_PRICE_NO_LONGER_FITS';

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $nonConforming  the keys of the prices that must be corrected
     */
    private function __construct(public bool $stale, public array $reasons, public array $nonConforming) {}

    /** @param  array<string, string>  $unitPrices  the draft's entered unit prices, by the draft's own line key */
    public static function review(RoundingPolicyBinding $bound, RoundingPolicy $now, array $unitPrices): self
    {
        $reasons = [];
        $stale = ! $bound->isCurrent($now);
        if ($stale) {
            $reasons[] = self::POLICY_CHANGED;
        }
        $nonConforming = [];
        foreach ($unitPrices as $key => $price) {
            if (! $now->conforms($price)) {
                $nonConforming[] = (string) $key;
            }
        }
        if ($nonConforming !== []) {
            $reasons[] = self::UNIT_PRICE_NO_LONGER_FITS;
        }

        return new self($stale || $nonConforming !== [], $reasons, $nonConforming);
    }

    public function requiresReview(): bool
    {
        return $this->stale;
    }
}
