<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;

enum PackageTrackingMode: string
{
    case QuantityOnly = 'QUANTITY_ONLY';
    case PackageBalance = 'PACKAGE_BALANCE';
    case ContainerTracked = 'CONTAINER_TRACKED';

    public function requireImplemented(): void
    {
        if ($this !== self::QuantityOnly) {
            throw new DomainInvariantException(
                'Package tracking activation is unavailable until stock-operation adapters and reconciliation are implemented.',
                'package_tracking_mode',
            );
        }
    }
}
