<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

enum PageOrientation: string
{
    case Portrait = 'PORTRAIT';
    case Landscape = 'LANDSCAPE';
}
