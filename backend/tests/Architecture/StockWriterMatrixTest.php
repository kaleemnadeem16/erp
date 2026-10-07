<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * ADR 0091 §3.4: code that creates stock moves or layers, or changes layer quantities, is listed here with what happens to it for an item
 * with grouped package tracking. The database guard stops any unlisted writer anyway; this test makes sure a new one is noticed and given
 * a decision (package-aware replacement, or an explicit refusal) instead of silently relying on that last line of defence.
 */
class StockWriterMatrixTest extends TestCase
{
    /** file => what a grouped (authoritative) item gets */
    private const WRITERS = [
        'Modules/Inventory/Actions/Movements/ReceiptPoster.php' => 'sole boundary: scalar receipt refused; package-aware receipt (RECEIPT_IN) runs inside PackageWriteScope',
        'Modules/Inventory/Actions/Movements/IssuePoster.php' => 'sole boundary: scalar issue refused; grouped issue runs inside PackageWriteScope',
        'Modules/Inventory/Actions/Movements/TransferPoster.php' => 'sole boundary: scalar transfer refused; grouped transfer runs inside PackageWriteScope',
        'Modules/Inventory/Actions/Cutover/OpeningStateItemPoster.php' => 'refused: cutover opening-state posting',
        'Modules/Inventory/Actions/ExecuteRecalculation.php' => 'refused: recalculation request and execution',
        'Modules/Inventory/Actions/Recalculation/FifoReceiptReallocator.php' => 'refused: runs only inside a refused recalculation',
        'Modules/Inventory/Actions/Recalculation/OutboundRecalculationExecutor.php' => 'refused: runs only inside a refused recalculation',
        'Modules/Inventory/Actions/Recalculation/LayerCounterfactualReplayer.php' => 'refused: runs only inside a refused recalculation',
    ];

    private const PATTERN = '/StockMove::query\(\)->create|StockMove::create|StockLayer::query\(\)->create|StockLayer::create|\'qty_remaining\' => \(string\)|->update\(\[\'qty_remaining\'/';

    public function test_every_code_path_that_writes_stock_rows_has_a_decision_for_grouped_items(): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Models/')) {
                continue;
            }
            if (preg_match(self::PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen(base_path('app')) + 1);
            }
        }
        sort($found);
        $listed = array_keys(self::WRITERS);
        sort($listed);

        $this->assertSame([], array_values(array_diff($found, $listed)), 'New code writes stock rows: decide whether it gets a package-aware replacement or a refusal for grouped items, then list it (docs/decisions/0091).');
        $this->assertSame([], array_values(array_diff($listed, $found)), 'Listed writer no longer writes stock rows: remove it from the matrix.');
    }

    public function test_the_entry_points_that_refuse_grouped_items_still_call_the_guard(): void
    {
        $guards = [
            'Modules/Inventory/Actions/PostInventoryMovement.php' => 4,
            'Modules/Inventory/Actions/PostPhysicalCount.php' => 1,
            'Modules/Inventory/Actions/Cutover/OpeningStateItemPoster.php' => 1,
            'Modules/Inventory/Actions/RequestStockRecalculation.php' => 1,
            'Modules/Inventory/Actions/ExecuteRecalculation.php' => 1,
            'Modules/Inventory/Actions/RequestNegativeStockOverride.php' => 1,
            'Modules/Purchasing/Actions/ReverseGoodsReceipt.php' => 1,
            'Modules/Purchasing/Actions/PostQualityDisposition.php' => 1,
        ];
        foreach ($guards as $file => $expected) {
            $this->assertGreaterThanOrEqual($expected, substr_count((string) file_get_contents(base_path('app/'.$file)), 'PackageWriteScope::refuse('), $file);
        }
    }
}
