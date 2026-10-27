<?php

namespace Tests\Architecture;

use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use Tests\TestCase;

/**
 * The Estimating cost-evidence seam (#29a) lets a workflow read the cost of an Assembly version WITHOUT `estimating.internal_cost.view`. That is safe only because the
 * set of callers is closed: this test pins it. A new consumer must be added here, deliberately, in the same change that names its `CostEvidenceWorkflow` — and be
 * reviewed as a new cost-disclosure path.
 */
class CostEvidenceSeamHasOneCallerTest extends TestCase
{
    /** File => why it may reference the seam. */
    private const ALLOWED = [
        'Modules/Estimating/Actions/ReadAssemblyCostEvidence.php' => 'The seam itself.',
        'Modules/Sales/Support/Financial/Controls/EstimatingAssemblyCostEvidence.php' => 'The Sales cost-blind evaluator\'s provider (#29b): the one consumer, under QUOTATION_CONTROL_EVALUATION.',
    ];

    public function test_only_the_named_workflows_may_reach_the_seam(): void
    {
        $offenders = [];
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }
            $relative = str_replace(app_path().'/', '', $file->getPathname());
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'ReadAssemblyCostEvidence') || str_contains($source, 'CostEvidenceWorkflow::')) {
                $seen[] = $relative;
                if (! array_key_exists($relative, self::ALLOWED) && ! str_starts_with($relative, 'Modules/Estimating/Support/CostEvidence')) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'Only a listed workflow may read cost evidence without the cost-read duty.');
        $this->assertSame([], array_values(array_diff(array_keys(self::ALLOWED), $seen)), 'A listed caller that no longer references the seam must be removed from the list.');
    }

    public function test_the_workflow_allowlist_is_exactly_the_reviewed_one(): void
    {
        $this->assertSame(['QUOTATION_CONTROL_EVALUATION'], array_map(fn (\UnitEnum $case): string => $case->value, CostEvidenceWorkflow::cases()));
    }
}
