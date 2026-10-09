<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Support\Facades\DB;

/** Keep an open opportunity's description, value, expected close and manual next action up to date. Who it is for, its owner, stage and outcome change only through their own operations. */
final class UpdateOpportunity
{
    /** @param array<string, mixed> $input */
    public function __invoke(Opportunity $opportunity, array $input, User $actor): Opportunity
    {
        SalesAccess::require($actor, PermissionVocabulary::OPPORTUNITY_MANAGE, 'change an opportunity');

        return DB::transaction(function () use ($opportunity, $input): Opportunity {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->outcome !== OpportunityOutcome::Open) {
                throw new DomainInvariantException('Reopen a closed opportunity before changing it.', 'outcome', 'OPPORTUNITY_CLOSED');
            }
            $changes = [];
            if (array_key_exists('title', $input)) {
                $title = trim((string) $input['title']);
                if ($title === '') {
                    throw new DomainInvariantException('An opportunity needs a title.', 'title');
                }
                $changes['title'] = $title;
            }
            foreach (['description', 'source', 'next_action'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = trim((string) $input[$field]);
                    $changes[$field] = $value === '' ? null : $value;
                }
            }
            foreach (['expected_close_on', 'next_action_on'] as $field) {
                if (array_key_exists($field, $input)) {
                    $changes[$field] = $input[$field] === '' ? null : $input[$field];
                }
            }
            if (array_key_exists('expected_amount', $input) || array_key_exists('expected_currency_id', $input)) {
                $changes += SalesRecords::expectedValue(
                    array_key_exists('expected_amount', $input) ? $input['expected_amount'] : $locked->expected_amount,
                    array_key_exists('expected_currency_id', $input) ? $input['expected_currency_id'] : $locked->expected_currency_id,
                );
            }
            if (array_key_exists('party_contact_id', $input)) {
                $changes['party_contact_id'] = SalesRecords::contactOf($locked->party, $input['party_contact_id'] === null ? null : (int) $input['party_contact_id'])?->id;
            }
            $locked->update($changes);

            return $locked->refresh();
        });
    }
}
