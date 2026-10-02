<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class ArchiveParty
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    public function __invoke(Party $party, User $actor): void
    {
        $this->access->require($actor, PermissionVocabulary::PARTY_MANAGE, $party->company_id);

        DB::transaction(function () use ($party): void {
            $party = Party::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            $hasActiveRole = $party->customerAccount()->exists()
                || $party->supplierAccount()->exists();

            if ($hasActiveRole) {
                throw new DomainInvariantException(
                    'Archive the customer and supplier accounts before archiving the party.',
                    'party',
                );
            }

            $party->delete();
        });
    }
}
