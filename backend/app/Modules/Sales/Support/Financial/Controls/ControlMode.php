<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

enum ControlMode: string
{
    /** A violation warns and asks for acknowledgement; missing evidence is reported UNEVALUABLE and does not block. */
    case Advisory = 'ADVISORY';

    /** A violation is FAIL and missing evidence is UNEVALUABLE; either prevents issue. */
    case Mandatory = 'MANDATORY';
}
