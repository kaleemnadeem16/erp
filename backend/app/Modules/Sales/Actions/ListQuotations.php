<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Lists only safe revision identities, after applying exact-duty family eligibility. */
final class ListQuotations
{
    /**
     * @param  array<string, mixed>  $input
     * @return LengthAwarePaginator<int, array{revision_id:int,family_id:int,revision_ordinal:int,state:string,recipient_party_id:int|null,currency_id:int,created_at:string|null,valid_through_local_date:string|null,responsible_owner_id:int|null}>
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

            $paginator = $query->with('family:id,responsible_owner_id')->paginate((int) ($input['per_page'] ?? 25), ['*'], 'page', (int) ($input['page'] ?? 1));
            $rows = $paginator->getCollection()->map(static fn (QuotationRevision $revision): array => [
                'revision_id' => (int) $revision->id,
                'family_id' => (int) $revision->family_id,
                'revision_ordinal' => (int) $revision->revision_ordinal,
                'state' => (string) $revision->state,
                'recipient_party_id' => $revision->recipient_party_id === null ? null : (int) $revision->recipient_party_id,
                'currency_id' => (int) $revision->currency_id,
                'created_at' => $revision->created_at?->toIso8601String(),
                // Safe header facts the detail contract already shows the same view holder; the owner is what the owner/mine filters already reveal.
                'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'),
                'responsible_owner_id' => $revision->family?->responsible_owner_id === null ? null : (int) $revision->family->responsible_owner_id,
            ]);

            // @phpstan-ignore return.type
            return new LengthAwarePaginator($rows, $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->getOptions());
        });
    }
}
