<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Conversion between UoMs is only valid within the same dimension — see
 * UomConversionService and docs/domain/units-of-measure.md.
 */
enum UomDimension: string
{
    case Area = 'AREA';
    case Volume = 'VOLUME';
    case Mass = 'MASS';
    case Length = 'LENGTH';
    case Time = 'TIME';
    case Count = 'COUNT';
}
