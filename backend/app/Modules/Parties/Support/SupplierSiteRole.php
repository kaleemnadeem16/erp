<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

/**
 * See docs/domain/parties.md "Shared sites" — role-specific meaning lives on the
 * supplier_sites join row, not on the shared party_site itself.
 */
enum SupplierSiteRole: string
{
    case OrderFrom = 'ORDER_FROM';
    case ShipFrom = 'SHIP_FROM';
}
