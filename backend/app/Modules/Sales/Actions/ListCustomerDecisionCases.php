<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\RecipientNames;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The shared intake cases of the company, newest first: identity, the customer Party (name only where the actor may read it), how many components and observations the case holds,
 * and its generation. Needs `sales.customer-decision.view` (STAGED); a limited scope of that duty is refused, never filtered. Reading counts nothing and records nothing; a case
 * says what the customer sent, never that they agreed.
 */
final class ListCustomerDecisionCases
{
    /**
     * @param  array<string, mixed>  $input
     * @return LengthAwarePaginator<int, array{id:int,party_id:int,party_name:string|null,intake_identity:string,generation:int,component_count:int,observation_count:int,recorded_by:int,recorded_at:string|null}>
     */
    public function __invoke(User $actor, array $input): LengthAwarePaginator
    {
        $this->early($actor);
        $input = Validator::make($input, [
            'filter' => ['sometimes', 'array:party_id,search'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:120'],
            'sort' => ['sometimes', Rule::in(['id', '-id'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        // @phpstan-ignore argument.type, return.type
        return DB::transaction(function () use ($actor, $input): LengthAwarePaginator {
            $fresh = CustomerDecisionAccess::decide($actor, PermissionVocabulary::CUSTOMER_DECISION_VIEW, 'see customer decisions');
            $query = CustomerDecisionCase::query()->withCount(['components', 'evidence']);
            $filter = $input['filter'] ?? [];
            if (isset($filter['party_id'])) {
                $query->where('party_id', (int) $filter['party_id']);
            }
            if (isset($filter['search']) && trim((string) $filter['search']) !== '') {
                $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $filter['search']));
                $query->where('intake_identity', 'ilike', "%{$needle}%");
            }
            $query->orderBy('id', ($input['sort'] ?? '-id') === 'id' ? 'asc' : 'desc');
            $page = $query->paginate((int) ($input['per_page'] ?? 25), ['*'], 'page', (int) ($input['page'] ?? 1));

            $names = app(RecipientNames::class)->for($fresh, $page->getCollection()->pluck('party_id')->unique()->values()->all());
            $rows = $page->getCollection()->map(static fn (CustomerDecisionCase $case): array => [
                'id' => (int) $case->id,
                'party_id' => (int) $case->party_id,
                'party_name' => $names[(int) $case->party_id] ?? null,
                'intake_identity' => $case->intake_identity,
                'generation' => (int) $case->generation,
                'component_count' => (int) $case->getAttribute('components_count'),
                'observation_count' => (int) $case->getAttribute('evidence_count'),
                'recorded_by' => (int) $case->recorded_by,
                'recorded_at' => $case->recorded_at->toIso8601String(),
            ]);

            // @phpstan-ignore return.type
            return new LengthAwarePaginator($rows, $page->total(), $page->perPage(), $page->currentPage(), $page->getOptions());
        });
    }

    private function early(User $actor): void
    {
        CustomerDecisionAccess::early($actor, PermissionVocabulary::CUSTOMER_DECISION_VIEW, 'see customer decisions');
    }
}
