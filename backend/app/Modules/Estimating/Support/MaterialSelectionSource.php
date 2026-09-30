<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** Which level supplied the effective strategy for an item, nearest first. */
enum MaterialSelectionSource: string
{
    case RequestOverride = 'REQUEST_OVERRIDE';
    case Item = 'ITEM';
    case ItemCategory = 'ITEM_CATEGORY';
    case Company = 'COMPANY';
    case BuiltIn = 'BUILT_IN_DEFAULT';
}
