<?php

namespace Tests\Architecture;

use App\Modules\Sales\Support\Handover\Release\SourceApplicabilityProof;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5 Batch 1 finding F2: a Sales-service Job lineage carries no material and no subcontractor obligation BY CONSTRUCTION, because no Inventory, Purchasing or Imports
 * table has any column or foreign key that can point at a Job or a handover. This ratchet fails the day one does; the release then refuses (SourceApplicabilityProof) until
 * that writer is fenced and reviewed by its owner.
 */
class JobLineageIsolationTest extends TestCase
{
    /** @return list<string> */
    private function guardedTables(): array
    {
        $tables = [];
        foreach (['Inventory', 'Purchasing', 'Imports'] as $module) {
            $root = base_path("app/Modules/{$module}");
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || ! str_contains($file->getPath(), DIRECTORY_SEPARATOR.'Models')) {
                    continue;
                }
                $class = 'App\\Modules\\'.$module.'\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                    $tables[] = (new $class)->getTable();
                }
            }
        }

        return array_values(array_unique($tables));
    }

    public function test_the_guarded_set_is_not_empty(): void
    {
        $this->assertGreaterThan(10, count($this->guardedTables()), 'the ratchet must actually inspect Inventory and Purchasing tables');
    }

    public function test_no_inventory_purchasing_or_imports_column_names_a_job_handover_or_lineage(): void
    {
        $offenders = [];
        foreach ($this->guardedTables() as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (preg_match('/job|handover|lineage/i', $column) === 1) {
                    $offenders[] = "{$table}.{$column}";
                }
            }
        }
        $this->assertSame([], $offenders, 'These writers could attach an obligation to a Job lineage; their owners must fence and review them first.');
    }

    public function test_no_inventory_purchasing_or_imports_table_references_the_job_lineage(): void
    {
        $guarded = $this->guardedTables();
        $rows = DB::select("SELECT c.conrelid::regclass::text AS src, c.confrelid::regclass::text AS dst FROM pg_constraint c WHERE c.contype = 'f'");
        $offenders = [];
        foreach ($rows as $row) {
            $src = str_contains($row->src, '.') ? substr($row->src, strrpos($row->src, '.') + 1) : $row->src;
            if (in_array($src, $guarded, true) && preg_match('/^(service_jobs|job_|handover_|service_completion_bases)/', $row->dst) === 1) {
                $offenders[] = "{$src} -> {$row->dst}";
            }
        }
        $this->assertSame([], $offenders);
        $this->assertSame('NOT_APPLICABLE', app(SourceApplicabilityProof::class)->evaluate()['status']);
    }

    public function test_a_foreign_table_referencing_the_lineage_makes_applicability_unknown(): void
    {
        DB::statement('CREATE TABLE zz_lineage_probe (id bigserial primary key, job_id bigint references service_jobs(id))');
        try {
            $proof = app(SourceApplicabilityProof::class)->evaluate();
            $this->assertSame('UNKNOWN', $proof['status']);
            $this->assertSame(['zz_lineage_probe'], $proof['referencing']);
        } finally {
            DB::statement('DROP TABLE zz_lineage_probe');
        }
    }
}
