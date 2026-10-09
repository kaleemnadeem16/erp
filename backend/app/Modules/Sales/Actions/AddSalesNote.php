<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesNote;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesRecords;

/** Add a human note, with its author and time, to an enquiry or an opportunity (open or closed). Append-only: a note is never edited. */
final class AddSalesNote
{
    public function __invoke(Lead|Opportunity $subject, string $body, User $actor): SalesNote
    {
        SalesAccess::require($actor, $subject instanceof Lead ? PermissionVocabulary::LEAD_MANAGE : PermissionVocabulary::OPPORTUNITY_MANAGE, 'add a note');
        SalesRecords::requireCompanyActor($actor);
        $body = trim($body);
        if ($body === '') {
            throw new DomainInvariantException('A note needs some text.', 'body');
        }

        return SalesNote::create([
            'lead_id' => $subject instanceof Lead ? $subject->id : null,
            'opportunity_id' => $subject instanceof Opportunity ? $subject->id : null,
            'author_id' => $actor->id,
            'body' => $body,
            'created_at' => now(),
        ])->refresh();
    }
}
