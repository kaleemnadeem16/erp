<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * ADR 0049's revised scope hierarchy — Assembly -> AssemblyGroup -> Company
 * -> none. Company is the only kind whose scope_id is NULL (see
 * OverheadPolicy); the other two point at a real assemblies/assembly_groups
 * row, validated at the application layer since scope_id carries no
 * database-level FK (it targets a different table depending on this value).
 */
enum OverheadScopeType: string
{
    case Company = 'COMPANY';
    case AssemblyGroup = 'ASSEMBLY_GROUP';
    case Assembly = 'ASSEMBLY';
}
