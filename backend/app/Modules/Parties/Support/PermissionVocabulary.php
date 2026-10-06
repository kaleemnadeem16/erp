<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

final class PermissionVocabulary
{
    public const PARTY_MANAGE = 'parties.party.manage';

    public const CUSTOMER_ACCOUNT_MANAGE = 'parties.customer-account.manage';

    public const SUPPLIER_ACCOUNT_MANAGE = 'parties.supplier-account.manage';

    public const SUPPLIER_SITE_MANAGE = 'parties.supplier-site.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PARTY_MANAGE,
            self::CUSTOMER_ACCOUNT_MANAGE,
            self::SUPPLIER_ACCOUNT_MANAGE,
            self::SUPPLIER_SITE_MANAGE,
            'parties.credit-limit.view',
            'parties.credit-limit.manage',
            'parties.payment-terms.manage',
        ];
    }
}
