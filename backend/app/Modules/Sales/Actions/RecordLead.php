<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\LeadIntake;
use App\Modules\Sales\Numbering\LeadNumbering;
use App\Modules\Sales\Support\CrmOperation;
use App\Modules\Sales\Support\LeadEnquiry;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Carbon\CarbonImmutable;

/**
 * Record an enquiry (ADR 0098/0108/0113). Optional by design: nothing requires a lead before an opportunity or a quotation. The enquiry is kept as
 * received in immutable intake evidence written in the same transaction as the lead, its receipt event and its retry receipt; the current enquiry
 * can later be corrected, the original never. Identity resolution and conversion add to it and never overwrite it (ADR 0105). May be unassigned.
 */
final class RecordLead
{
    public function __construct(private readonly NumberingService $numbering) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{lead: Lead, replayed: bool}
     */
    public function __invoke(array $input, User $actor, ?string $requestKey): array
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'record an enquiry');
        SalesRecords::requireCompanyActor($actor);
        $details = [];
        foreach (LeadEnquiry::FIELDS as $field) {
            $details[$field] = LeadEnquiry::text($input[$field] ?? null);
        }
        if ($details['enquirer_name'] === null) {
            throw new DomainInvariantException('Who is asking? An enquiry needs a name.', 'enquirer_name');
        }
        $assignee = $input['assigned_to'] ?? null;
        if ($assignee !== null) {
            SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_ASSIGN, 'assign an enquiry');
        }
        $receivedAt = isset($input['received_at']) ? CarbonImmutable::parse((string) $input['received_at'])->toIso8601String() : null;

        $ran = CrmOperation::run(
            $actor, 'LEAD_RECORD', $requestKey,
            ['details' => $details, 'received_at' => $receivedAt, 'party_id' => $input['party_id'] ?? null, 'assigned_to' => $assignee], null, null,
            static fn () => null,
            function () use ($details, $input, $actor, $assignee, $receivedAt): array {
                $party = isset($input['party_id']) ? SalesRecords::activeParty((int) $input['party_id']) : null;
                $owner = $assignee === null ? null : SalesRecords::eligibleMember((int) $assignee, 'assigned_to');
                $lead = Lead::create($details + [
                    'status' => LeadStatus::New,
                    'received_at' => $receivedAt ?? now(),
                    'party_id' => $party?->id,
                    'assigned_to' => $owner?->id,
                    'created_by' => $actor->id,
                ]);
                $lead->forceFill(['number' => $this->numbering->allocate(LeadNumbering::contextFor($lead->id, CarbonImmutable::now(), $actor->id))->number])->save();
                // The enquiry exactly as accepted: the database refuses a lead that commits without it.
                LeadIntake::create($details + [
                    'lead_id' => $lead->id, 'evidence_kind' => LeadIntake::ORIGINAL, 'captured_at' => now(), 'captured_by' => $actor->id,
                    'received_at' => $lead->received_at, 'recorded_creator_id' => $actor->id,
                ]);
                SalesRecords::record($actor, SalesEvent::LEAD_RECEIVED, $lead, null, null, ['source' => $lead->source, 'party_id' => $party?->id, 'assigned_to' => $owner?->id]);

                return ['version' => 1, 'lead_id' => $lead->id];
            },
            fn (array $result): array => [(int) $result['lead_id'], null],
        );

        return ['lead' => Lead::query()->findOrFail((int) $ran['result']['lead_id']), 'replayed' => $ran['replayed']];
    }
}
