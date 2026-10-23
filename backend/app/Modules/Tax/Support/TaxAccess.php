<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;

final class TaxAccess
{
    public static function requireConfiguration(User $actor): void
    {
        $duty = TaxDutyManifest::CONFIGURE;
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()
            || ! app(TaxDutyManifest::class)->isActive($duty)
            || ! $actor->can($duty)
            || ! app(RecordScope::class)->resolve($actor, $duty)->isAll()) {
            throw new AuthorizationException('Tax configuration is not available to this actor.');
        }
    }
}
