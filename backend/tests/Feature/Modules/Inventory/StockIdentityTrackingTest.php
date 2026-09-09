<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 acceptance criterion: "batch/serial requirements follow
 * items.tracking, not valuation_method". Every accepting path was covered
 * already; none of StockIdentity's rejection branches were, so a regression
 * that let an untracked item carry a batch — or a serial line receive more
 * than one unit — would have passed CI.
 */
class StockIdentityTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): StockIdentity
    {
        return app(StockIdentity::class);
    }

    private function item(ItemTracking $tracking, ValuationMethod $method = ValuationMethod::Avco): Item
    {
        $uom = Uom::create(['code' => 'ea-'.uniqid(), 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'cat-'.uniqid(), 'name' => 'Category', 'default_valuation_method' => $method->value]);

        return Item::create([
            'code' => 'item-'.uniqid(), 'name' => 'Item', 'type' => 'RAW',
            'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'tracking' => $tracking->value, 'valuation_method' => $method->value,
        ]);
    }

    public function test_an_untracked_item_cannot_carry_a_batch_or_serial(): void
    {
        $item = $this->item(ItemTracking::None);

        foreach ([['B1', null], [null, 'S1']] as [$batchNo, $serialNo]) {
            try {
                $this->identity()->validateReceiptTracking($item, BigDecimal::of('1'), $batchNo, $serialNo);
                $this->fail('Untracked identity must be rejected.');
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_batch_tracked_item_requires_a_batch_and_refuses_a_serial(): void
    {
        $item = $this->item(ItemTracking::Batch);

        $this->expectException(DomainInvariantException::class);
        $this->identity()->validateReceiptTracking($item, BigDecimal::of('1'), null, null);
    }

    public function test_a_batch_tracked_item_cannot_also_carry_a_serial(): void
    {
        $item = $this->item(ItemTracking::Batch);

        $this->expectException(DomainInvariantException::class);
        $this->identity()->validateReceiptTracking($item, BigDecimal::of('1'), 'B1', 'S1');
    }

    public function test_a_serial_receipt_must_be_exactly_one_unit_with_a_serial(): void
    {
        $item = $this->item(ItemTracking::Serial, ValuationMethod::Specific);

        foreach ([['2', 'S1'], ['1', null]] as [$qty, $serialNo]) {
            try {
                $this->identity()->validateReceiptTracking($item, BigDecimal::of($qty), null, $serialNo);
                $this->fail('Serial receipts must be one unit with one serial.');
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_serial_issue_refuses_a_batch_or_a_quantity_other_than_one(): void
    {
        $item = $this->item(ItemTracking::Serial, ValuationMethod::Specific);

        foreach ([['1', 'B1', 'S1'], ['3', null, 'S1']] as [$qty, $batchNo, $serialNo]) {
            try {
                $this->identity()->validateIssueTracking($item, BigDecimal::of($qty), $batchNo, $serialNo);
                $this->fail('Serial issues must be one unit with one serial and no batch.');
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tracking_rules_are_independent_of_valuation_method(): void
    {
        // A batch-tracked item is batch-tracked under either method: FIFO and
        // AVCO both accept the batch and both reject its absence.
        foreach ([ValuationMethod::Avco, ValuationMethod::Fifo] as $method) {
            $item = $this->item(ItemTracking::Batch, $method);

            $this->identity()->validateReceiptTracking($item, BigDecimal::of('5'), 'B1', null);

            try {
                $this->identity()->validateReceiptTracking($item, BigDecimal::of('5'), null, null);
                $this->fail("A batch was required under {$method->value}.");
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->addToAssertionCount(1);
    }
}
