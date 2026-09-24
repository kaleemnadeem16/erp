<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Which tier of ADR 0049's scope hierarchy produced the resolved policy —
 * surfaced to the caller rather than silently returning a rate, same
 * transparency principle as ResolutionMethod for application rates. None is
 * not an error (docs/domain/assemblies.md "Overhead allocation": "not an
 * error, overhead/unit = 0") — the one legitimate terminal state this
 * resolver has that ResolveApplicationRate/ResolveFormulation don't.
 */
enum OverheadResolutionMethod: string
{
    case Assembly = 'ASSEMBLY';
    case AssemblyGroup = 'ASSEMBLY_GROUP';
    case Company = 'COMPANY';
    case None = 'NONE';
}
