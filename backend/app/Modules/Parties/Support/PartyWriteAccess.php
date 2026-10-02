<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Shared capability and tenant guard for HTTP and non-HTTP master-data writes. */
final class PartyWriteAccess
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function require(User $actor, string $permission, ?int $companyId = null): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
        if ($companyId !== null && $companyId !== $this->companyContext->id()) {
            throw (new ModelNotFoundException)->setModel(Party::class);
        }
    }
}
