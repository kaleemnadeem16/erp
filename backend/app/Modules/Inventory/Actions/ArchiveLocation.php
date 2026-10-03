<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Support\ActionAuthorization;

final class ArchiveLocation
{
    public function __construct(private readonly UpdateLocation $update) {}

    public function __invoke(Location $location, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.location.manage', 'archive a location');
        ($this->update)($location, ['active' => false], $actor);
    }
}
