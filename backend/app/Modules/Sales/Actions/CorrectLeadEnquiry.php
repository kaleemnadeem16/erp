<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\LeadEnquiryCorrection;
use App\Modules\Sales\Support\CrmOperation;
use App\Modules\Sales\Support\LeadEnquiry;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesEvent;
use App\Modules\Sales\Support\SalesRecords;

/**
 * Correct the current details of an enquiry, in any lifecycle state (ADR 0113). The enquiry as received stays untouched in its immutable intake; the six
 * current details change only with a reason, against the version the caller saw, with the exact old and new value of each changed detail recorded and one
 * history reference. Status, Party link, assignee, receipt date and derived deals are never changed here. A request that changes nothing creates no
 * correction and does not advance the version, but keeps its retry receipt.
 */
final class CorrectLeadEnquiry
{
    /**
     * @param  array<string, mixed>  $input  any of the six enquiry details (null clears one)
     * @return array{lead: Lead, operation: array<string, mixed>, replayed: bool}
     */
    public function __invoke(Lead $lead, array $input, ?string $reason, ?string $requestKey, ?int $expectedVersion, User $actor): array
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'correct an enquiry');
        SalesRecords::requireCompanyActor($actor);
        $reason = SalesRecords::requireReason($reason, 'correct an enquiry');
        if ($expectedVersion === null) {
            throw new DomainInvariantException('Say which version of the enquiry you are correcting.', 'expected_enquiry_version', 'STALE_EVIDENCE');
        }
        $asked = [];
        foreach (LeadEnquiry::FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $asked[$field] = LeadEnquiry::text($input[$field]);
            }
        }
        if ($asked === []) {
            throw new DomainInvariantException('Give at least one enquiry detail to correct.', 'enquirer_name');
        }
        if (array_key_exists('enquirer_name', $asked) && $asked['enquirer_name'] === null) {
            throw new DomainInvariantException('An enquiry needs a name.', 'enquirer_name');
        }

        $ran = CrmOperation::run(
            $actor, 'LEAD_ENQUIRY_CORRECT', $requestKey,
            ['reason' => $reason, 'expected_enquiry_version' => $expectedVersion, 'details' => $asked], $lead->id, null,
            fn () => Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail(),
            function () use ($lead, $asked, $reason, $expectedVersion, $actor): array {
                $locked = Lead::query()->whereKey($lead->id)->firstOrFail();
                if ($locked->enquiry_version !== $expectedVersion) {
                    throw new DomainInvariantException('This enquiry was corrected since you opened it; review the current details and correct again.', 'expected_enquiry_version', 'STALE_EVIDENCE');
                }
                $changes = [];
                foreach ($asked as $field => $value) {
                    if ($locked->getAttribute($field) !== $value) {
                        $changes[$field] = $value;
                    }
                }
                if ($changes === []) {
                    return ['version' => 1, 'lead_id' => $locked->id, 'enquiry_version' => $locked->enquiry_version, 'correction_id' => null, 'changed' => false];
                }
                $correction = LeadEnquiryCorrection::create([
                    'lead_id' => $locked->id, 'actor_id' => $actor->id, 'recorded_at' => now(), 'reason' => $reason,
                    'previous_version' => $locked->enquiry_version, 'resulting_version' => $locked->enquiry_version + 1,
                ]);
                foreach ($changes as $field => $value) {
                    $correction->fields()->create(['field_name' => $field, 'old_value' => $locked->getAttribute($field), 'new_value' => $value]);
                }
                $locked->update($changes + ['enquiry_version' => $locked->enquiry_version + 1]);
                // A reference to the evidence, not a copy of it: the values live in the correction.
                SalesRecords::record($actor, SalesEvent::LEAD_ENQUIRY_CORRECTED, $locked, null, $reason, ['correction_id' => $correction->id, 'fields' => array_keys($changes), 'enquiry_version' => $locked->enquiry_version]);

                return ['version' => 1, 'lead_id' => $locked->id, 'enquiry_version' => $locked->enquiry_version, 'correction_id' => $correction->id, 'changed' => true];
            },
        );

        return ['lead' => Lead::query()->findOrFail($lead->id), 'operation' => $ran['result'], 'replayed' => $ran['replayed']];
    }
}
