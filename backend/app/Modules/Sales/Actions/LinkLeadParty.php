<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/**
 * A person decides which trading partner an enquiry is about (ADR 0105): an existing one, a restored one, or one registered just now. Nothing is matched,
 * merged or overwritten automatically, and no customer account is created.
 */
final class LinkLeadParty
{
    public function __invoke(Lead $lead, int $partyId, User $actor): Lead
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'link an enquiry to a trading partner');

        return DB::transaction(function () use ($lead, $partyId, $actor): Lead {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === LeadStatus::Disqualified) {
                throw new DomainInvariantException('Revive the enquiry before linking it to a trading partner.', 'status', 'LEAD_DISQUALIFIED');
            }
            $party = SalesRecords::activeParty($partyId);
            if ($locked->party_id === $party->id) {
                return $locked;
            }
            $previous = $locked->party_id;
            $locked->update(['party_id' => $party->id]);
            SalesRecords::record($actor, SalesEvent::LEAD_PARTY_LINKED, $locked, null, null, ['from_party_id' => $previous, 'to_party_id' => $party->id, 'to_party_code' => $party->code, 'to_party_name' => $party->name]);

            return $locked->refresh();
        });
    }
}
