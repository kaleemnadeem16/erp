<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Kernel;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Support\CompanyContext;

/**
 * The explicit human actor and company every internal Sales kernel operation runs for (AU2/AU5 interface). It carries identity only: it is not a
 * permission verdict, and building one confers no authority. Each guarded Action that later calls the kernel still checks its own duty and scope
 * (invariant 12); a service identity with its own purpose/fence is a later AU5 slice and is deliberately not modelled here.
 */
final readonly class SalesActorContext
{
    private function __construct(public User $actor, public int $companyId, public string $purpose) {}

    public static function forUser(User $actor, string $purpose): self
    {
        $company = app(CompanyContext::class)->id();
        if ((int) $actor->company_id !== $company) {
            throw new DomainInvariantException('The actor is outside the active company.', 'actor', 'ACTOR_OUTSIDE_COMPANY');
        }
        if (trim($purpose) === '') {
            throw new DomainInvariantException('The purpose of the operation is required.', 'purpose', 'PURPOSE_REQUIRED');
        }

        return new self($actor, $company, trim($purpose));
    }
}
