<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Current protected evaluation or paged immutable summaries; no raw stored snapshots leave this boundary. */
final class ReadQuotationDiscountAuthority
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId, bool $history = false, int $page = 1, int $perPage = 25): array
    {
        return DB::transaction(function () use ($actor, $revisionId, $history, $page, $perPage): array {
            app(CompanyAccessFence::class)->lockAll();
            app(AuthorityFence::class)->lock();
            $fresh = ActiveActor::require($actor, 'read protected discount authority evidence');
            if ($fresh->must_change_password) {
                throw new AuthorizationException('Complete the password change before reading protected evidence.');
            }
            SalesAccess::require($fresh, PermissionVocabulary::AUTHORITY_EVIDENCE_VIEW);
            $revision = app(QuotationAccess::class)->requireRevision($fresh, $revisionId, [PermissionVocabulary::QUOTATION_VIEW]);
            if (! $history && ! $revision->isDraft()) {
                $pinned = DB::table('quotation_issues as i')->leftJoin('sales_discount_evaluations as e', function ($join): void {
                    $join->on('e.company_id', '=', 'i.company_id')->on('e.id', '=', 'i.authority_evaluation_id');
                })->where('i.company_id', $revision->company_id)->where('i.revision_id', $revisionId)->select(['e.snapshot', 'e.mode'])->first();

                return ['revision_id' => $revisionId, 'mode' => $pinned->mode ?? 'OFF', 'current' => $pinned?->snapshot === null ? null : $this->project(json_decode($pinned->snapshot, true, flags: JSON_THROW_ON_ERROR)),
                    'records' => [], 'page' => 1, 'per_page' => 25, 'total' => 0];
            }
            if (! $history) {
                return ['revision_id' => $revisionId, 'mode' => app(DiscountConfiguration::class)->mode(), 'current' => $this->project(app(AssessDiscountAuthority::class)->assess($revision)), 'records' => [], 'page' => 1, 'per_page' => 25, 'total' => 0];
            }
            $query = DB::table('sales_discount_evaluations')->where('company_id', $revision->company_id)->where('revision_id', $revisionId);
            $total = $query->count();
            $perPage = max(1, min(100, $perPage));
            $page = max(1, $page);
            $records = $query->select(['id', 'actor_id', 'mode', 'input_generation', 'recorded_at', 'snapshot'])->orderByDesc('id')->forPage($page, $perPage)->get()->map(function ($row): array {
                return ['id' => (int) $row->id, 'actor_id' => (int) $row->actor_id, 'mode' => $row->mode, 'input_generation' => (int) $row->input_generation,
                    'recorded_at' => Carbon::parse($row->recorded_at)->utc()->toIso8601String(), 'evaluation' => $this->project(json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))];
            })->all();

            return ['revision_id' => $revisionId, 'mode' => app(DiscountConfiguration::class)->mode(), 'current' => null, 'records' => $records, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
        });
    }

    /** @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private function project(array $assessment): array
    {
        $measure = $assessment['measure'];

        $lines = [];
        foreach (($measure['lines'] ?? []) as $line) {
            $lines[] = ['line_id' => (int) $line['line_id'], 'reference_id' => (int) $line['reference_id'], 'reference_unit_price' => $line['reference_unit_price'],
                'reference_amount' => $line['reference_amount'], 'final_net_amount' => $line['final_net'], 'reduction_amount' => $line['reduction_amount'],
                'reduction_base_amount' => $line['reduction_base_amount'], 'document_allocation' => $line['document_allocation'], 'fx_fingerprint' => $line['fx_fingerprint']];
        }

        return ['lines' => $lines, 'result' => $assessment['result'], 'reasons' => $assessment['reasons'], 'fingerprint' => $assessment['fingerprint'], 'policy_id' => $assessment['policy_id'],
            'required_rank' => $assessment['required_rank'], 'required_band_id' => $assessment['required_band_id'],
            'currency_id' => $measure['currency_id'] ?? null, 'base_currency_id' => $measure['base_currency_id'] ?? null,
            'reference_amount' => $measure['reference_amount'] ?? null, 'reduction_amount' => $measure['reduction_amount'] ?? null,
            'reduction_base_amount' => $measure['reduction_base_amount'] ?? null, 'final_net_amount' => $measure['final_net_amount'] ?? null,
            'document_percent_exact' => $measure['document_percent'] ?? null];
    }
}
