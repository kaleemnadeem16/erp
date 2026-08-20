<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * Everything the system buys, holds, makes, consumes or sells shares one
 * items table with this type discriminator, rather than being split into
 * separate product/service/resource tables — see docs/domain/items.md
 * "Types". LABOUR and PLANT are items so assembly components can reference
 * one thing uniformly.
 */
enum ItemType: string
{
    case Raw = 'RAW';
    case SemiFinished = 'SEMI_FINISHED';
    case Finished = 'FINISHED';
    case Service = 'SERVICE';
    case Labour = 'LABOUR';
    case Plant = 'PLANT';
}
