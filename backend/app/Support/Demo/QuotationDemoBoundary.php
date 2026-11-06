<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Support\CompanyContext;

/** Only handout is demo-specific; record authority never consults this boundary. */
final class QuotationDemoBoundary
{
    public const COMPANY_ID = 1;

    public const OPERATOR_EMAIL = 'demo-access-operator@erp.x1techs.com';

    public const MAIN_EMAIL = 'user@erp.x1techs.com';

    /** @var list<string> */
    public const DUTIES = [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, Duty::QUOTATION_ASSIGN, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE];

    public static function assertCompany(int $companyId): void
    {
        if ((! app()->environment(['local', 'testing']) && config('demo.mode') !== true)
            || $companyId !== self::COMPANY_ID || app(CompanyContext::class)->id() !== $companyId) {
            throw new DomainInvariantException('Quotation demo access is unavailable in this environment or company.', 'company_id');
        }
    }

    public static function assertMainPatch(User $member, User $actor): void
    {
        self::assertCompany((int) $member->company_id);
        if ((int) $actor->company_id !== self::COMPANY_ID
            || $member->email !== self::MAIN_EMAIL || $actor->email !== self::OPERATOR_EMAIL) {
            throw new DomainInvariantException('Quotation demo access is restricted to the approved synthetic operator and main demo login.', 'role_ids');
        }
    }
}
