<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions\Access;

use App\Models\User;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;

/** Minimal directory identities for one governed family; listing a person never authorises a grant. */
final class ReadQuotationAccessCandidates
{
    /** @param array<string,mixed> $filters
     * @return CursorPaginator<int,\stdClass>
     */
    public function __invoke(User $actor, int $familyId, string $kind, string $level = 'VIEW', array $filters = []): CursorPaginator
    {
        return DB::transaction(function () use ($actor, $familyId, $kind, $level, $filters): CursorPaginator {
            $duties = [Duty::QUOTATION_VIEW, $kind === 'recipient' ? Duty::QUOTATION_ACCESS_DELEGATE : Duty::QUOTATION_ASSIGN,
                ...($kind === 'recipient' && $level === 'PREPARE' ? [Duty::QUOTATION_PREPARE] : [])];
            $family = app(QuotationAccess::class)->requireFamily($actor, $familyId, $duties, false, false);
            $pageSize = filter_var($filters['per_page'] ?? 25, FILTER_VALIDATE_INT);
            $filter = $filters['filter'] ?? [];
            if (! is_array($filter)) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use a supported search filter.');
            }
            $search = $filter['search'] ?? '';
            $cursor = $filters['cursor'] ?? null;
            if (! in_array($kind, ['owner', 'recipient', 'unit'], true) || ! in_array($level, ['VIEW', 'PREPARE'], true)
                || $pageSize === false || $pageSize < 1 || $pageSize > 50 || ! is_string($search) || mb_strlen($search) > 100
                || ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 1024))
                || array_diff(array_keys($filters), ['filter', 'per_page', 'cursor']) !== []
                || array_diff(array_keys($filter), ['search']) !== []) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use a supported candidate kind, search and bounded page size.');
            }
            $parsedCursor = null;
            if ($cursor !== null) {
                $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
                if (! is_array($decoded) || array_diff(array_keys($decoded), ['id', '_pointsToNextItems']) !== []
                    || ! is_int($decoded['id'] ?? null) || $decoded['id'] < 1 || ! is_bool($decoded['_pointsToNextItems'] ?? null)) {
                    throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use a valid pagination cursor.', 'cursor');
                }
                $parsedCursor = new Cursor(['id' => $decoded['id']], $decoded['_pointsToNextItems']);
            }
            $query = DB::table($kind === 'unit' ? 'organisation_units' : 'users')->where('company_id', $family->company_id)->where('status', 'ACTIVE')->select(['id', 'name']);
            if ($kind === 'recipient') {
                $query->where('id', '<>', $actor->id);
            }
            if ($search !== '') {
                $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
            }

            return $query->orderBy('id')->cursorPaginate($pageSize, ['id', 'name'], 'cursor', $parsedCursor)->appends(['kind' => $kind, 'level' => $level, 'filter' => $filter, 'per_page' => $pageSize]);
        });
    }
}
