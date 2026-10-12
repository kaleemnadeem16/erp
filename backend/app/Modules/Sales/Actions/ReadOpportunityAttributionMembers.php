<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people a historical correction may name (ADR 0114): members of this company, including former colleagues who have since been suspended, as id,
 * name and status only. It serves the correction duty and nothing wider: it is not the owner lookup, not a member directory and not a Party lookup.
 */
final class ReadOpportunityAttributionMembers
{
    public const LIMIT = 25;

    /** @return list<array{id: int, name: string, status: string}> */
    public function __invoke(Opportunity $opportunity, ?string $search, ?int $id, User $actor): array
    {
        SalesAccess::requireCorrectAttribution($actor);
        SalesRecords::requireCompanyActor($actor);
        $query = User::query()->where('company_id', $opportunity->company_id)->orderBy('name')->limit(self::LIMIT);
        if ($id !== null) {
            $query->whereKey($id);
        }
        if ($search !== null && trim($search) !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', '%'.trim($search).'%'));
        }
        $members = [];
        foreach ($query->get(['id', 'name', 'status']) as $member) {
            $members[] = ['id' => (int) $member->id, 'name' => (string) $member->name, 'status' => (string) $member->status->value];
        }

        return $members;
    }
}
