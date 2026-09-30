<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * ACTIVE is the one row currently answering for its scope; SUPERSEDED rows are
 * the kept history. There is no DRAFT: a strategy is a single choice among three
 * named presets, so there is nothing to author before it takes effect.
 */
enum MaterialSelectionPolicyStatus: string
{
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}
