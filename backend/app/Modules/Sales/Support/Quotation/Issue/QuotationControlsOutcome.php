<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use InvalidArgumentException;

/**
 * What the controls provider can say: every applicable mandatory control was evaluated and none blocks (PASS, or WARN when only advisory controls were violated) with a
 * fingerprint of what was judged, or named blockers (a mandatory control failed or could not be evaluated, or none is configured). An empty control set is not a pass here.
 */
final readonly class QuotationControlsOutcome
{
    /** @param  list<IssueBlocker>  $blockers */
    private function __construct(public bool $passed, public ?string $outcome, public ?string $fingerprint, public array $blockers) {}

    public static function passed(string $outcome, ?string $fingerprint = null): self
    {
        if (! in_array($outcome, ['PASS', 'WARN'], true) || ($fingerprint !== null && preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1)) {
            throw new InvalidArgumentException('Passed controls are PASS or WARN with an optional 64-character fingerprint.');
        }

        return new self(true, $outcome, $fingerprint, []);
    }

    /** @param  list<IssueBlocker>  $blockers */
    public static function blocked(array $blockers): self
    {
        if ($blockers === []) {
            throw new InvalidArgumentException('Blocked controls name at least one blocker.');
        }

        return new self(false, null, null, $blockers);
    }
}
