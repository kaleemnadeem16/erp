<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

/** Whether an absolute amount in a control or an input is for the whole line or for one priced unit (then multiplied by the exact priced quantity). */
enum ControlBasis: string
{
    case Line = 'LINE';
    case PerUnit = 'PER_UNIT';
}
