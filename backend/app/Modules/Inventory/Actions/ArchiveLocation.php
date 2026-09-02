<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;

final class ArchiveLocation
{
    public function __construct(private readonly UpdateLocation $update) {}

    public function __invoke(Location $location): void
    {
        ($this->update)($location, ['active' => false]);
    }
}
