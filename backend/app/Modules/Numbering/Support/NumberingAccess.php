<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use App\Models\User;

/** Who may see and change numbering. Reading needs numbering.view; changing a type's series needs the permission that type registers. */
final class NumberingAccess
{
    public function __construct(private readonly NumberingRegistry $registry) {}

    public function canView(?User $user): bool
    {
        return $user?->can('numbering.view') === true;
    }

    public function canManage(?User $user, string $docType): bool
    {
        return $this->registry->has($docType) && $user?->can($this->registry->get($docType)->managePermission()) === true;
    }
}
