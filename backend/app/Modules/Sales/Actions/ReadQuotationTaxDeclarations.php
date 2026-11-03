<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every version of the tax nature and classification a seller declared for the manual lines of a draft, oldest first, so a correction can be seen beside what it corrected. Needs
 * `sales.quotation.prepare` with the independent view, judged by the quotation-family policy; a quotation outside that policy is not found. Carries the seller's own words and the
 * actor and time of each version, and nothing Tax decided: no rate, profile fact or amount.
 */
final class ReadQuotationTaxDeclarations
{
    /** @return list<array{line_id: int|null, scope_ref: string, version_no: int, nature: string, classification: string, reason: string, declared_by: int, declared_at: string}> */
    public function __invoke(User $actor, int $revisionId): array
    {
        return DB::transaction(function () use ($actor, $revisionId): array {
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]);
            $lines = $revision->lines()->pluck('id', 'logical_scope_id');

            return DB::table('quotation_draft_tax_declarations as d')
                ->join('quotation_logical_scopes as s', fn ($join) => $join->on('s.company_id', '=', 'd.company_id')->on('s.id', '=', 'd.logical_scope_id'))
                ->where('d.revision_id', $revision->id)->orderBy('d.logical_scope_id')->orderBy('d.version_no')
                ->get(['d.logical_scope_id', 's.intent_ref', 'd.version_no', 'd.nature', 'd.classification', 'd.reason', 'd.declared_by', 'd.declared_at'])
                ->map(fn ($row): array => [
                    'line_id' => isset($lines[$row->logical_scope_id]) ? (int) $lines[$row->logical_scope_id] : null, 'scope_ref' => (string) $row->intent_ref, 'version_no' => (int) $row->version_no,
                    'nature' => (string) $row->nature, 'classification' => (string) $row->classification, 'reason' => (string) $row->reason, 'declared_by' => (int) $row->declared_by,
                    'declared_at' => Carbon::parse((string) $row->declared_at)->toIso8601String(),
                ])->values()->all();
        });
    }
}
