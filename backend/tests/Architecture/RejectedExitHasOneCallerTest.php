<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * ADR 0097: the movement that takes rejected stock out of a named delivery layer ignores what is issuable, because rejected quantity never is. That power is safe
 * only behind the exit Action, which proves the quantity is finally rejected and uncapped first. So `issueRejectedPosted` — and the `from_layer_id` input that
 * selects the layer — may appear only in the movement boundary, its issue poster and `PostRejectedStockExit`.
 */
class RejectedExitHasOneCallerTest extends TestCase
{
    private const ALLOWED = [
        'Modules/Inventory/Actions/PostInventoryMovement.php',
        'Modules/Inventory/Actions/PostRejectedStockExit.php',
        'Modules/Inventory/Actions/Movements/IssuePoster.php',
    ];

    public function test_only_the_exit_action_may_consume_a_named_layer(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }
            $relative = str_replace(app_path().'/', '', $file->getPathname());
            $source = file_get_contents($file->getPathname());
            if ((str_contains($source, 'issueRejectedPosted(') || str_contains($source, "'from_layer_id'") || str_contains($source, 'allocateFromLayer(')) && ! in_array($relative, self::ALLOWED, true)
                && $relative !== 'Modules/Inventory/Actions/Movements/LayerAllocator.php') {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'A named-layer outbound may be requested only by PostRejectedStockExit.');
    }
}
