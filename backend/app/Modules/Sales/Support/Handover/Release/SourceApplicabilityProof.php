<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Support\CompanyContext;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use Illuminate\Support\Facades\DB;

/**
 * The no-material / no-subcontractor applicability proof for the pilot lineage (Phase 5 Batch 1, finding F2 as corrected by #122).
 *
 * NOT_APPLICABLE needs ALL THREE of: (1) the customer-agreed scope itself EXCLUDES both materials and subcontractors, stated by a basis that carries its own
 * customer-agreement evidence (EVIDENCE_TEXT) — never by SERVICE_ITEM, an empty query, a note or a setting; (2) EVERY source family (Inventory, Purchasing) has its owner's
 * registered authority answering NOT_APPLICABLE, meaning that family has no supported, validated Job binding (generic source provenance is never a Job allocation);
 * (3) no table outside the Jobs and Sales handover owners references the lineage (the foreign-key catalogue, kept as a secondary check).
 * APPLICABLE: the agreed scope REQUIRES materials or subcontractors, or a family reports a supported binding — the first pilot refuses. Everything else is UNKNOWN,
 * including an unregistered required family: a missing authority is never a "no".
 *
 * The result is pinned (see {@see pin()}) in the release's relied-on facts and re-proved at every later boundary.
 */
final class SourceApplicabilityProof
{
    public const VERSION = 'SOURCE_APPLICABILITY.2';

    /** The lineage tables: nothing else may reference them. */
    private const LINEAGE = ['service_jobs', 'handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence', 'handover_release_events', 'service_completion_bases'];

    /**
     * @return array{status:string,version:string,scope:array{materials:string,subcontract:string,basis:?array{id:int,version:int,terms_source:string}},families:array<string,array{status:string,contract:string,reason:?string}>,referencing:list<string>,pin:string}
     */
    public function evaluate(?ResolvedCompletionBasis $basis): array
    {
        $declared = $basis !== null && $basis->declaresScope();
        $materials = $declared ? $basis->materialsScope : 'UNSTATED';
        $subcontract = $declared ? $basis->subcontractScope : 'UNSTATED';

        $families = [];
        $anyApplicable = false;
        $allNotApplicable = true;
        foreach (app(JobSourceFamilies::class)->assessAll(app(CompanyContext::class)->id()) as $assessment) {
            $families[$assessment->family] = ['status' => $assessment->status, 'contract' => $assessment->contractVersion, 'reason' => $assessment->reason];
            $anyApplicable = $anyApplicable || $assessment->status === FamilyAssessment::APPLICABLE;
            $allNotApplicable = $allNotApplicable && $assessment->status === FamilyAssessment::NOT_APPLICABLE;
        }
        $referencing = $this->referencing();

        if ($materials === 'REQUIRED' || $subcontract === 'REQUIRED' || $anyApplicable) {
            $status = FamilyAssessment::APPLICABLE;
        } elseif ($materials === 'EXCLUDED' && $subcontract === 'EXCLUDED' && $allNotApplicable && $referencing === []) {
            $status = FamilyAssessment::NOT_APPLICABLE;
        } else {
            $status = FamilyAssessment::UNKNOWN;
        }
        $result = ['status' => $status, 'version' => self::VERSION,
            'scope' => ['materials' => $materials, 'subcontract' => $subcontract, 'basis' => $basis === null ? null : ['id' => $basis->basisId, 'version' => $basis->version, 'terms_source' => $basis->termsSource]],
            'families' => $families, 'referencing' => $referencing];
        $result['pin'] = self::pin($result);

        return $result;
    }

    /**
     * A stable digest of everything the answer relied on: status, contract version, the basis head and its scopes, every family's status and contract version, the catalogue.
     *
     * @param  array<string,mixed>  $result
     */
    public static function pin(array $result): string
    {
        $core = ['status' => $result['status'], 'version' => $result['version'], 'scope' => $result['scope'],
            'families' => array_map(fn (array $f): array => ['status' => $f['status'], 'contract' => $f['contract']], $result['families']), 'referencing' => $result['referencing']];

        return hash('sha256', json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The secondary check: tables outside the lineage owners that reference the lineage by a foreign key.
     *
     * @return list<string>
     */
    public function referencing(): array
    {
        $rows = DB::select(
            "SELECT DISTINCT c.conrelid::regclass::text AS tbl FROM pg_constraint c WHERE c.contype = 'f' AND c.confrelid = ANY (ARRAY[".implode(',', array_map(fn (string $t): string => "'{$t}'::regclass", self::LINEAGE)).'])',
        );
        $foreign = [];
        foreach ($rows as $row) {
            $table = (string) $row->tbl;
            if (! $this->isOwner($table)) {
                $foreign[] = $table;
            }
        }
        sort($foreign);

        return $foreign;
    }

    /** The Jobs module's own tables (service_jobs, job_*) and the Sales handover/completion tables are the only legitimate referrers. */
    private function isOwner(string $table): bool
    {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        return $table === 'service_jobs' || $table === 'service_completion_bases' || str_starts_with($table, 'job_') || str_starts_with($table, 'handover_');
    }
}
