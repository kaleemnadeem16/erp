<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use Illuminate\Support\Facades\DB;

/**
 * Genuine no-material / no-subcontractor proof for the pilot lineage (Phase 5 Batch 1 finding F2). Inventory, Purchasing and Imports writers can attach an obligation to a
 * service lineage only through a foreign key into the Job or handover tables; none exists, so a catalogue read proves the lineage cannot carry one. The proof is
 * evaluated at release and at every later fence: if ANY table outside the Jobs and Sales handover owners references the lineage, applicability is UNKNOWN and the
 * release/start refuses. An empty query, a company setting, a human declaration or the SERVICE_ITEM discriminator is never accepted instead.
 */
final class SourceApplicabilityProof
{
    public const VERSION = 'SOURCE_APPLICABILITY.1';

    /** The lineage tables: nothing else may reference them. */
    private const LINEAGE = ['service_jobs', 'handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence', 'handover_release_events', 'service_completion_bases'];

    /** @return array{status:string,version:string,referencing:list<string>} status is NOT_APPLICABLE (proved absent) or UNKNOWN */
    public function evaluate(): array
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

        return ['status' => $foreign === [] ? 'NOT_APPLICABLE' : 'UNKNOWN', 'version' => self::VERSION, 'referencing' => $foreign];
    }

    /** The Jobs module's own tables (service_jobs, job_*) and the Sales handover/completion tables are the only legitimate referrers. */
    private function isOwner(string $table): bool
    {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        return $table === 'service_jobs' || $table === 'service_completion_bases' || str_starts_with($table, 'job_') || str_starts_with($table, 'handover_');
    }
}
