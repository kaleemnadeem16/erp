<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * Stable business meanings (ADR 0107), separate from the configurable OPEN stages. WON requires authoritative recorded customer acceptance, which does not
 * exist until quotation acceptance does; no Action can set it yet.
 */
enum OpportunityOutcome: string
{
    case Open = 'OPEN';
    case Won = 'WON';
    case Lost = 'LOST';
    case Abandoned = 'ABANDONED';
}
