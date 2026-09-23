<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Which step of docs/domain/application-rates.md#resolution produced the
 * winning rate. Surfaced to the caller (and, later, to the UI) rather than
 * silently returning a rate — a silent default fallback is exactly what
 * that doc's "Silent fallback" landmine warns against.
 */
enum ResolutionMethod: string
{
    case ExactMatch = 'EXACT_MATCH';
    case RequiredOnlyMatch = 'REQUIRED_ONLY_MATCH';
    case Default = 'DEFAULT';
}
