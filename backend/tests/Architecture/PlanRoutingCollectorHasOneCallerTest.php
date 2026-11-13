<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * The plan routing collector carries the real internal-cost figures an approval needs while the Estimating preview runs for a cost-blind adopter. It is the narrow side channel that
 * lets Sales route a Material Plan approval correctly without the preview returning cost to that person, so its users are a closed set: the previews that fill it and the one
 * Sales evaluator that reads it. A new reader must be added here deliberately and reviewed as a new cost-disclosure path (ADR 0190).
 */
class PlanRoutingCollectorHasOneCallerTest extends TestCase
{
    /** File => why it may mention the collector. */
    private const ALLOWED = [
        'Modules/Estimating/Support/PlanRoutingCollector.php' => 'The collector itself.',
        'Modules/Estimating/Actions/PreviewAssemblyMaterials.php' => 'Hands it to the two previews that fill it.',
        'Modules/Estimating/Actions/PreviewMaterialVariance.php' => 'Records the cost delta of a planned quantity.',
        'Modules/Estimating/Actions/PreviewInventoryAvailability.php' => 'Records the cost consequence of a hand-chosen mix.',
        'Modules/Sales/Support/MaterialPlan/EvaluateMaterialPlan.php' => 'The one reader: copies only what the plan evidence keeps.',
    ];

    public function test_only_the_plan_evaluator_may_read_the_routing_collector(): void
    {
        $offenders = [];
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }
            $relative = str_replace(app_path().'/', '', $file->getPathname());
            if (preg_match('/\bPlanRoutingCollector\b/', (string) file_get_contents($file->getPathname())) === 1) {
                $seen[] = $relative;
                if (! array_key_exists($relative, self::ALLOWED)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'Only the plan evaluator may read the routing collector.');
        $this->assertSame([], array_values(array_diff(array_keys(self::ALLOWED), $seen)), 'A listed file that no longer references the collector must be removed from the list.');
    }
}
