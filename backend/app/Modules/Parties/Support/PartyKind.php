<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

/** What kind of contracting identity a Party is (ADR 0105). Independent of its customer/supplier roles. */
enum PartyKind: string
{
    case Organisation = 'ORGANISATION';
    case Individual = 'INDIVIDUAL';
}
