<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Numbering\LeadNumbering;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record an enquiry (ADR 0098/0108). Optional by design: nothing requires a lead before an opportunity or a quotation. The enquiry is kept as received;
 * identity resolution and conversion add to it later and never overwrite it (ADR 0105). May be unassigned.
 */
final class RecordLead
{
    public function __construct(private readonly NumberingService $numbering) {}

    /** @param array<string, mixed> $input */
    public function __invoke(array $input, User $actor): Lead
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'record an enquiry');
        SalesRecords::requireCompanyActor($actor);
        $name = trim((string) ($input['enquirer_name'] ?? ''));
        if ($name === '') {
            throw new DomainInvariantException('Who is asking? An enquiry needs a name.', 'enquirer_name');
        }
        $assignee = $input['assigned_to'] ?? null;
        if ($assignee !== null) {
            SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_ASSIGN, 'assign an enquiry');
        }

        return DB::transaction(function () use ($input, $actor, $name, $assignee): Lead {
            $party = isset($input['party_id']) ? SalesRecords::activeParty((int) $input['party_id']) : null;
            $owner = $assignee === null ? null : SalesRecords::eligibleMember((int) $assignee, 'assigned_to');
            $lead = Lead::create([
                'status' => LeadStatus::New,
                'enquirer_name' => $name,
                'enquirer_organisation' => self::text($input['enquirer_organisation'] ?? null),
                'enquirer_email' => self::text($input['enquirer_email'] ?? null),
                'enquirer_phone' => self::text($input['enquirer_phone'] ?? null),
                'description' => self::text($input['description'] ?? null),
                'source' => self::text($input['source'] ?? null),
                'received_at' => $input['received_at'] ?? now(),
                'party_id' => $party?->id,
                'assigned_to' => $owner?->id,
                'created_by' => $actor->id,
            ]);
            $lead->forceFill(['number' => $this->numbering->allocate(LeadNumbering::contextFor($lead->id, CarbonImmutable::now(), $actor->id))->number])->save();
            SalesRecords::record($actor, SalesEvent::LEAD_RECEIVED, $lead, null, null, ['source' => $lead->source, 'party_id' => $party?->id, 'assigned_to' => $owner?->id]);

            return $lead->refresh();
        });
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
