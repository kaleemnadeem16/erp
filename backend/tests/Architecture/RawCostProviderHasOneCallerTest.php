<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * The Sales raw-cost provider hands back a real `FinancialResult` with confidential amounts, so it is confined: only the guarded evaluator may call it. (The cost-evidence
 * seam under it also re-asks Sales' authority for the person on every read, but a closed set of callers is the second wall.) A new consumer must be added here
 * deliberately and reviewed as a new cost-disclosure path.
 */
class RawCostProviderHasOneCallerTest extends TestCase
{
    /** File => why it may mention the provider types. */
    private const ALLOWED = [
        'Modules/Sales/Actions/EvaluateAssemblyLineControls.php' => 'The guarded cost-blind evaluator: the one caller.',
        'Modules/Sales/Support/Financial/Controls/AssemblyCostEvidenceProvider.php' => 'The interface.',
        'Modules/Sales/Support/Financial/Controls/EstimatingAssemblyCostEvidence.php' => 'The implementation over the Estimating seam.',
        'Modules/Sales/Support/Financial/Controls/UnavailableAssemblyCostEvidence.php' => 'The implementation that knows nothing.',
        'Modules/Sales/SalesServiceProvider.php' => 'Binds the interface to its implementation.',
    ];

    public function test_only_the_guarded_evaluator_may_reach_the_raw_cost_provider(): void
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
            if (preg_match('/\b(AssemblyCostEvidenceProvider|EstimatingAssemblyCostEvidence|UnavailableAssemblyCostEvidence)\b/', $source) === 1) {
                $seen[] = $relative;
                if (! array_key_exists($relative, self::ALLOWED)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'Only the guarded evaluator may call the raw-cost provider.');
        $this->assertSame([], array_values(array_diff(array_keys(self::ALLOWED), $seen)), 'A listed file that no longer references the provider must be removed from the list.');
    }
}
