<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Models\SalesStage;
use App\Support\CompanyContext;

/** Small shared rules for the Sales Actions, so each Action states its own business step and nothing else. */
final class SalesRecords
{
    /** The actor must belong to the company the action runs in. */
    public static function requireCompanyActor(User $actor): void
    {
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The actor must belong to the current company.', 'actor');
        }
    }

    /** An owner or assignee is an active member of this company (ADR 0106); anyone else is refused, never silently granted anything. */
    public static function eligibleMember(int $userId, string $field): User
    {
        $user = User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($userId)->first();
        if ($user === null) {
            throw new DomainInvariantException('That person is not a member of this company.', $field, 'OWNER_NOT_ELIGIBLE');
        }
        if ($user->status !== MemberStatus::Active) {
            throw new DomainInvariantException('Only an active member can be made responsible.', $field, 'OWNER_NOT_ELIGIBLE');
        }

        return $user;
    }

    public static function activeParty(int $partyId, string $field = 'party_id'): Party
    {
        $party = Party::query()->find($partyId);
        if ($party === null) {
            throw new DomainInvariantException('That trading partner does not exist in this company, or is archived.', $field, 'PARTY_NOT_AVAILABLE');
        }

        return $party;
    }

    public static function contactOf(Party $party, ?int $contactId): ?PartyContact
    {
        if ($contactId === null) {
            return null;
        }
        $contact = PartyContact::query()->where('party_id', $party->id)->whereKey($contactId)->first();
        if ($contact === null) {
            throw new DomainInvariantException('That contact does not belong to this trading partner.', 'party_contact_id', 'CONTACT_NOT_OF_PARTY');
        }

        return $contact;
    }

    public static function activeStage(int $stageId): SalesStage
    {
        $stage = SalesStage::query()->find($stageId);
        if ($stage === null || $stage->status !== SalesStage::ACTIVE) {
            throw new DomainInvariantException('Choose one of the active stages.', 'stage_id', 'STAGE_NOT_AVAILABLE');
        }

        return $stage;
    }

    public static function firstActiveStage(): SalesStage
    {
        return SalesStage::query()->where('status', SalesStage::ACTIVE)->orderBy('sort_order')->orderBy('id')->first()
            ?? throw new DomainInvariantException('No pipeline stages are configured yet.', 'stage_id', 'NO_STAGES_CONFIGURED');
    }

    /** @param  array<string, mixed>  $details */
    public static function record(User $actor, string $event, ?Lead $lead, ?Opportunity $opportunity, ?string $reason = null, array $details = []): SalesHistory
    {
        return SalesHistory::create([
            'lead_id' => $lead?->id,
            'opportunity_id' => $opportunity?->id,
            'event' => $event,
            'actor_id' => $actor->id,
            'occurred_at' => now(),
            'reason' => $reason,
            'details' => $details,
        ]);
    }

    public static function requireReason(?string $reason, string $what): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainInvariantException("A reason is required to {$what}.", 'reason', 'REASON_REQUIRED');
        }

        return $reason;
    }

    /**
     * An expected value is both an amount and its currency, or neither (unknown stays unknown, never zero — ADR 0108).
     *
     * @return array{expected_amount: ?string, expected_currency_id: ?int}
     */
    public static function expectedValue(mixed $amount, mixed $currencyId): array
    {
        $hasAmount = $amount !== null && $amount !== '';
        $hasCurrency = $currencyId !== null && $currencyId !== '';
        if ($hasAmount !== $hasCurrency) {
            throw new DomainInvariantException('An expected value needs both an amount and its currency, or neither.', 'expected_amount', 'EXPECTED_VALUE_INCOMPLETE');
        }
        if ($hasAmount && (! is_numeric($amount) || (float) $amount < 0)) {
            throw new DomainInvariantException('The expected value must be zero or more.', 'expected_amount');
        }

        return ['expected_amount' => $hasAmount ? (string) $amount : null, 'expected_currency_id' => $hasCurrency ? (int) $currencyId : null];
    }
}
