<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

enum MemberStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
