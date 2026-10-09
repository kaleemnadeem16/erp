<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Numbering\OpportunityNumbering;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create an opportunity: directly for a trading partner (a quotation never needs a lead first — ADR 0098/0108), or by converting an enquiry. One enquiry can
 * yield several genuine deals; converting twice with the same `conversion_key` returns the deal already created instead of duplicating it (ADR 0105).
 * The owner must be an active member (ADR 0106) and defaults to the person creating it. No customer account is created.
 */
final class CreateOpportunity
{
    public function __construct(private readonly NumberingService $numbering) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{opportunity: Opportunity, replayed: bool}
     */
    public function __invoke(array $input, User $actor, ?Lead $lead = null): array
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_MANAGE, 'create an opportunity');
        SalesRecords::requireCompanyActor($actor);
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new DomainInvariantException('An opportunity needs a title.', 'title');
        }
        $ownerId = isset($input['owner_id']) ? (int) $input['owner_id'] : $actor->id;
        if ($ownerId !== $actor->id) {
            SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_ASSIGN, 'make someone else responsible');
        }
        $key = $lead === null ? null : trim((string) ($input['conversion_key'] ?? ''));
        if ($lead !== null && $key === '') {
            throw new DomainInvariantException('A conversion needs a key so that retrying it cannot create the deal twice.', 'conversion_key', 'CONVERSION_KEY_REQUIRED');
        }
        $value = SalesRecords::expectedValue($input['expected_amount'] ?? null, $input['expected_currency_id'] ?? null);

        return DB::transaction(function () use ($input, $actor, $lead, $title, $ownerId, $key, $value): array {
            if ($lead !== null) {
                $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
                $existing = Opportunity::query()->where('lead_id', $lead->id)->where('conversion_key', $key)->first();
                if ($existing !== null) {
                    return ['opportunity' => $existing, 'replayed' => true];
                }
                if ($lead->status === LeadStatus::Disqualified) {
                    throw new DomainInvariantException('Revive a disqualified enquiry before converting it.', 'status', 'LEAD_DISQUALIFIED');
                }
            }
            $partyId = $input['party_id'] ?? $lead?->party_id;
            if ($partyId === null) {
                throw new DomainInvariantException('Choose the trading partner this opportunity is for.', 'party_id', 'PARTY_REQUIRED');
            }
            $party = SalesRecords::activeParty((int) $partyId);
            $contact = SalesRecords::contactOf($party, isset($input['party_contact_id']) ? (int) $input['party_contact_id'] : null);
            $owner = SalesRecords::eligibleMember($ownerId, 'owner_id');
            $stage = isset($input['stage_id']) ? SalesRecords::activeStage((int) $input['stage_id']) : SalesRecords::firstActiveStage();

            $opportunity = Opportunity::create([
                'title' => $title,
                'description' => self::text($input['description'] ?? $lead?->description),
                'party_id' => $party->id,
                'party_contact_id' => $contact?->id,
                'lead_id' => $lead?->id,
                'conversion_key' => $key,
                'source' => self::text($input['source'] ?? $lead?->source),
                'owner_id' => $owner->id,
                'stage_id' => $stage->id,
                'outcome' => OpportunityOutcome::Open,
                'expected_close_on' => $input['expected_close_on'] ?? null,
                'next_action' => self::text($input['next_action'] ?? null),
                'next_action_on' => $input['next_action_on'] ?? null,
                'created_by' => $actor->id,
            ] + $value);
            $opportunity->forceFill(['number' => $this->numbering->allocate(OpportunityNumbering::contextFor($opportunity->id, CarbonImmutable::now(), $actor->id))->number])->save();

            if ($lead !== null) {
                if ($lead->party_id === null) {
                    $lead->update(['party_id' => $party->id]);
                }
                if ($lead->status === LeadStatus::New) {
                    $lead->update(['status' => LeadStatus::Qualified, 'qualified_at' => now(), 'qualified_by' => $actor->id]);
                    SalesRecords::record($actor, SalesEvent::LEAD_QUALIFIED, $lead, null, 'Converted to an opportunity');
                }
                SalesRecords::record($actor, SalesEvent::LEAD_CONVERTED, $lead, $opportunity, null, ['opportunity_id' => $opportunity->id, 'conversion_key' => $key]);
            }
            SalesRecords::record($actor, SalesEvent::OPPORTUNITY_CREATED, $lead, $opportunity, null, [
                'party_id' => $party->id, 'owner_id' => $owner->id, 'stage_id' => $stage->id, 'stage_name' => $stage->name, 'from_lead_id' => $lead?->id,
            ]);

            return ['opportunity' => $opportunity->refresh(), 'replayed' => false];
        });
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
