<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use LogicException;

/** @internal Count and page share one statement snapshot, even inside a caller's READ COMMITTED transaction. */
final class QuotationListSnapshot
{
    /**
     * @param  Builder<QuotationRevision>  $query  already restricted by the exact-duty family policy
     * @return LengthAwarePaginator<int, QuotationRevision>
     */
    public function paginate(Builder $query, int $perPage, int $page, string $field, bool $descending): LengthAwarePaginator
    {
        if (! in_array($field, ['id', 'created_at', 'state'], true)) {
            throw new LogicException('Unsupported quotation list ordering.');
        }
        $source = (clone $query)->reorder()->select(['id', 'company_id', 'family_id', 'revision_ordinal', 'state', 'recipient_party_id', 'currency_id',
            'created_at', 'valid_through_local_date', 'official_number', 'expires_at'])->toBase();
        $order = '"'.$field.'" '.($descending ? 'DESC' : 'ASC').($field === 'id' ? '' : ', id ASC');
        // NOT MATERIALIZED lets PostgreSQL use count/page indexes separately, within ONE MVCC
        // snapshot, without buffering every eligible row. Only the page is aggregated to JSON.
        /** @var object{total: int|string, rows: string} $result */
        $result = DB::selectOne('WITH eligible AS NOT MATERIALIZED ('.$source->toSql().')
            SELECT (SELECT count(*) FROM eligible) AS total,
                COALESCE((SELECT jsonb_agg(to_jsonb(page_row)) FROM
                    (SELECT * FROM eligible ORDER BY '.$order.' LIMIT ? OFFSET ?) AS page_row), \'[]\'::jsonb) AS rows',
            [...$source->getBindings(), $perPage, ($page - 1) * $perPage]);
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($result->rows, true, flags: JSON_THROW_ON_ERROR);
        $models = QuotationRevision::hydrate($rows)->load('family:id,responsible_owner_id');

        return new LengthAwarePaginator($models, (int) $result->total, $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']);
    }
}
