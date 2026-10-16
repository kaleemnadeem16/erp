<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

/** The four outcomes of a final-net control (ADR 0116). UNEVALUABLE is never a PASS and never zero: the evidence needed was not there. */
enum ControlOutcome: string
{
    case Pass = 'PASS';
    case Warn = 'WARN';
    case Fail = 'FAIL';
    case Unevaluable = 'UNEVALUABLE';

    /** Overall precedence: a known violation outranks missing evidence, which outranks a warning, which outranks a pass. */
    public function severity(): int
    {
        return match ($this) {
            self::Pass => 0,
            self::Warn => 1,
            self::Unevaluable => 2,
            self::Fail => 3,
        };
    }
}
