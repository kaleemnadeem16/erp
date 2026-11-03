<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\Quotation\QuotationListSnapshot;
use App\Modules\Sales\Support\Quotation\RecipientNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Lists only safe revision identities, after applying exact-duty family eligibility, plus two sensitive facts that are each decided PER ROW inside the same transaction and fence:
 *
 *  - `recipient_name`: the recipient Party's name, only for a Party the actor may read. Party read has no duty and no record scope (every active member of the company reads Parties,
 *    and the tenant scope already keeps another company's Party out), so the gate is exactly that: an active member and a Party visible under the company scope. Should Parties
 *    ever gain a read duty or scope, `RecipientNames` is the one place that changes.
 *  - `net_amount`: the frozen net total of an ISSUED revision, only where the actor ALSO holds `sales.quotation.commercial.view` for that row's family (OWN = its current responsible
 *    owner, ALL = the company), judged by the same `QuotationAccess` policy as the list. A draft shows null: a list never reprices, and a draft's settled figure is a preview, not a fact.
 *
 * A hidden value is null, exactly as an absent one is, so the answer never says which. Neither value can be filtered or sorted on.
 */
final class ListQuotations
{
    /**
     * The frozen net total of each ISSUED revision on the page whose family the actor may see commercial figures for. Not holding the duty at all is the same as holding it for no row.
     *
     * @param  list<QuotationRevision>  $revisions
     * @return array<int, string>
     */
    private function netAmounts(User $actor, array $revisions): array
    {
        $issued = array_values(array_filter($revisions, static fn (QuotationRevision $r): bool => $r->state === 'ISSUED'));
        if ($issued === []) {
            return [];
        }
        try {
            $eligible = app(QuotationAccess::class)->queryFor($actor, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_COMMERCIAL_VIEW])
                ->whereIn('quotation_families.id', array_map(static fn (QuotationRevision $r): int => (int) $r->family_id, $issued))->pluck('quotation_families.id')->map(fn ($id): int => (int) $id)->all();
        } catch (AuthorizationException) {
            return [];
        }
        $revisionIds = array_map(static fn (QuotationRevision $r): int => (int) $r->id, array_filter($issued, static fn (QuotationRevision $r): bool => in_array((int) $r->family_id, $eligible, true)));

        return $revisionIds === [] ? [] : QuotationIssue::query()->whereIn('revision_id', $revisionIds)->get(['revision_id', 'net_total'])
            ->mapWithKeys(fn (QuotationIssue $issue): array => [(int) $issue->revision_id => (string) $issue->net_total])->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return LengthAwarePaginator<int, array{revision_id:int,family_id:int,revision_ordinal:int,state:string,recipient_party_id:int|null,currency_id:int,created_at:string|null,valid_through_local_date:string|null,official_number:string|null,availability:string,responsible_owner_id:int|null,recipient_name:string|null,net_amount:string|null}>
     */
    public function __invoke(User $actor, array $input): LengthAwarePaginator
    {
        $input = Validator::make($input, [
            'filter' => ['sometimes', 'array:state,recipient_party_id,owner_id,mine'],
            'filter.state' => ['sometimes', Rule::in(['DRAFT', 'CANCELLED_DRAFT', 'ISSUED'])],
            'filter.recipient_party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.owner_id' => ['sometimes', 'integer', 'min:1'],
            'filter.mine' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['id', '-id', 'created_at', '-created_at', 'state', '-state'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        // PHPStan treats the paginator item type as invariant across the materialised safe projection.
        // @phpstan-ignore argument.type, return.type
        return DB::transaction(function () use ($actor, $input): LengthAwarePaginator {
            $eligible = app(QuotationAccess::class)->queryFor($actor, [PermissionVocabulary::QUOTATION_VIEW])->select('quotation_families.id');
            $query = QuotationRevision::query()->whereIn('family_id', $eligible);
            $filter = $input['filter'] ?? [];
            foreach (['state', 'recipient_party_id'] as $field) {
                if (isset($filter[$field])) {
                    $query->where($field, $filter[$field]);
                }
            }
            if (isset($filter['owner_id'])) {
                $query->whereHas('family', fn ($family) => $family->where('responsible_owner_id', (int) $filter['owner_id']));
            }
            if (filter_var($filter['mine'] ?? false, FILTER_VALIDATE_BOOL)) {
                $query->whereHas('family', fn ($family) => $family->where('responsible_owner_id', $actor->id));
            }
            $sort = (string) ($input['sort'] ?? '-created_at');
            $field = ltrim($sort, '-');
            $query->orderBy($field, str_starts_with($sort, '-') ? 'desc' : 'asc');
            if ($field !== 'id') {
                $query->orderBy('id');
            }

            $paginator = app(QuotationListSnapshot::class)->paginate($query, (int) ($input['per_page'] ?? 25), (int) ($input['page'] ?? 1), $field, str_starts_with($sort, '-'));
            $page = $paginator->getCollection();
            $names = app(RecipientNames::class)->for($actor, $page->pluck('recipient_party_id')->filter()->unique()->values()->all());
            $nets = $this->netAmounts($actor, $page->all());
            $rows = $page->map(static fn (QuotationRevision $revision): array => [
                'revision_id' => (int) $revision->id,
                'family_id' => (int) $revision->family_id,
                'revision_ordinal' => (int) $revision->revision_ordinal,
                'state' => (string) $revision->state,
                'recipient_party_id' => $revision->recipient_party_id === null ? null : (int) $revision->recipient_party_id,
                'currency_id' => (int) $revision->currency_id,
                'created_at' => $revision->created_at?->toIso8601String(),
                // Safe header facts the detail contract already shows the same view holder; the owner is what the owner/mine filters already reveal.
                'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'),
                // The official number exists only once issued; availability is derived (DRAFT, CANCELLED_DRAFT, AVAILABLE, EXPIRED, WITHDRAWN, REPLACED), never stored.
                'official_number' => $revision->official_number,
                'availability' => QuotationAvailability::of($revision),
                'responsible_owner_id' => $revision->family?->responsible_owner_id === null ? null : (int) $revision->family->responsible_owner_id,
                'recipient_name' => $revision->recipient_party_id === null ? null : ($names[(int) $revision->recipient_party_id] ?? null),
                'net_amount' => $nets[(int) $revision->id] ?? null,
            ]);

            // @phpstan-ignore return.type
            return new LengthAwarePaginator($rows, $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->getOptions());
        });
    }
}
