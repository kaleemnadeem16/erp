<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use App\Modules\Sales\Support\Financial\Certainty;

/**
 * The non-throwing form of a resolution, for a caller that needs to say "this converted figure is UNKNOWN because…" rather than refuse the whole job
 * (a cost in another currency, for instance). It is COMPLETE with evidence or UNKNOWN with reasons. A conversion is never PARTIAL: one known leg of a
 * ratio says nothing about the amount, so there is no honest lower bound to carry.
 */
final readonly class FxOutcome
{
    /** @param  list<array{code: string, detail: string|null, leg: string}>  $reasons */
    private function __construct(public Certainty $certainty, public ?FxBundleEvidence $evidence, public array $reasons) {}

    public static function complete(FxBundleEvidence $evidence): self
    {
        return new self(Certainty::Complete, $evidence, []);
    }

    /** @param  list<array{code: string, detail: string|null, leg: string}>  $reasons */
    public static function unknown(array $reasons): self
    {
        return new self(Certainty::Unknown, null, $reasons);
    }
}
