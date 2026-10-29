<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use InvalidArgumentException;

/**
 * What the tax provider can say about a whole revision: COMPLETE (every line has a determined and calculated tax, with the calculation version, the Tax generation it was
 * read at and a fingerprint of all the evidence) or UNKNOWN (named blockers, never an amount). There is no PARTIAL: a quotation is issued with its whole tax or not at all.
 */
final readonly class QuotationTaxOutcome
{
    /**
     * @param  array<int, QuotationLineTax>  $lines  keyed by line id
     * @param  list<IssueBlocker>  $blockers
     */
    private function __construct(public bool $complete, public array $lines, public ?string $calculationVersion, public ?int $generation, public ?string $evidenceFingerprint, public array $blockers) {}

    /** @param  array<int, QuotationLineTax>  $lines */
    public static function complete(array $lines, string $calculationVersion, int $generation, string $evidenceFingerprint): self
    {
        if ($lines === [] || trim($calculationVersion) === '' || $generation < 0 || preg_match('/^[0-9a-f]{64}$/', $evidenceFingerprint) !== 1) {
            throw new InvalidArgumentException('Complete tax evidence names every line, its calculation version, its Tax generation and a 64-character fingerprint.');
        }

        return new self(true, $lines, $calculationVersion, $generation, $evidenceFingerprint, []);
    }

    /** @param  list<IssueBlocker>  $blockers */
    public static function unknown(array $blockers): self
    {
        if ($blockers === []) {
            throw new InvalidArgumentException('Unknown tax names at least one blocker.');
        }

        return new self(false, [], null, null, null, $blockers);
    }
}
