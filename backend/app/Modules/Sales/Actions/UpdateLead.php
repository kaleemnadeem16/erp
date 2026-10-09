<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/** Correct the description or source of an enquiry that is still new. The enquiry as received is not rewritten once someone has decided about it (ADR 0105). */
final class UpdateLead
{
    /** @param array<string, mixed> $input */
    public function __invoke(Lead $lead, array $input, User $actor): Lead
    {
        SalesAccess::require($actor, PermissionVocabulary::LEAD_MANAGE, 'change an enquiry');

        return DB::transaction(function () use ($lead, $input): Lead {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LeadStatus::New) {
                throw new DomainInvariantException('An enquiry that has been decided about is kept as received.', 'status', 'LEAD_NOT_NEW');
            }
            $changes = [];
            foreach (['enquirer_name', 'enquirer_organisation', 'enquirer_email', 'enquirer_phone', 'description', 'source'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = trim((string) $input[$field]);
                    if ($field === 'enquirer_name' && $value === '') {
                        throw new DomainInvariantException('An enquiry needs a name.', 'enquirer_name');
                    }
                    $changes[$field] = $value === '' ? null : $value;
                }
            }
            $locked->update($changes);

            return $locked->refresh();
        });
    }
}
