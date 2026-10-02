<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Where a stored selection strategy applies (ADR 0053 §2, ADR 0071): one item,
 * an item category (and, by inheritance, the categories beneath it) or the
 * whole company. COMPANY is the only scope whose scope_id is NULL.
 */
enum MaterialSelectionScope: string
{
    case Company = 'COMPANY';
    case ItemCategory = 'ITEM_CATEGORY';
    case Item = 'ITEM';
}
