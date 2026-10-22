<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** What a header or footer band shows on a page. A closed set: there is no free-form band content or markup. */
enum PageBandMode: string
{
    case Full = 'FULL';
    case Compact = 'COMPACT';
    case PageNumber = 'PAGE_NUMBER';
    case PageNumberOfTotal = 'PAGE_NUMBER_OF_TOTAL';
    case None = 'NONE';

    /** @return list<self> */
    public static function headerModes(): array
    {
        return [self::Full, self::Compact, self::None];
    }

    /** @return list<self> */
    public static function footerModes(): array
    {
        return [self::PageNumber, self::PageNumberOfTotal, self::None];
    }
}
