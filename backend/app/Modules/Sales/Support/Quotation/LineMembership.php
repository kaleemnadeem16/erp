<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** How a line takes part in the offer (R2 E). Competing alternatives are never summed: a choose-one group contributes its baseline once. */
enum LineMembership: string
{
    /** In the base offer. */
    case Base = 'BASE';

    /** Offered alongside, outside the base. */
    case Optional = 'OPTIONAL';

    /** One of a group of competing alternatives; exactly one is the baseline. */
    case ChooseOne = 'CHOOSE_ONE';
}
