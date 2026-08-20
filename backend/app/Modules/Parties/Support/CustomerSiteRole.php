<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

/**
 * See docs/domain/parties.md "Shared sites" — role-specific meaning lives on the
 * customer_sites join row, not on the shared party_site itself.
 */
enum CustomerSiteRole: string
{
    case BillTo = 'BILL_TO';
    case ShipTo = 'SHIP_TO';
}
