<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\ApproveInventoryCutoverNegativeOpening;
use App\Modules\Inventory\Actions\CommitInventoryCutoverOpeningState;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverNegativeOpening;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\NegativeStockShortage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The demo cutover carries two negative opening balances (ADR 0037's cutover-shortage exception): one whose
 * exception is approved by a second actor, one left requested so committing is refused until the demo login
 * approves it. Each claim is proved by driving the real Actions, not by reading the seeded rows back.
 */
final class CutoverNegativeOpeningDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    public function test_one_exception_is_approved_by_a_different_actor_and_the_other_waits(): void
    {
        $approved = $this->exception('demo-cutover-avco-short');
        $waiting = $this->exception('demo-cutover-fifo-short');

        $this->assertSame('APPROVED', $approved->status->value);
        $this->assertNotSame($approved->requested_by, $approved->approved_by);
        $this->assertSame('-25.0000', $approved->source_quantity);
        $this->assertSame('125.0000', $approved->provisional_unit_cost);
        $this->assertSame('REQUESTED', $waiting->status->value);
        $this->assertNull($waiting->approved_by);
        $this->assertSame('APPROVED', InventoryCutover::query()->sole()->status->value);
    }

    public function test_committing_is_refused_until_the_waiting_exception_is_approved_then_opens_both_shortages(): void
    {
        $cutover = InventoryCutover::query()->sole();
        $demo = User::query()->where('email', config('demo.user.email'))->sole();

        try {
            app(CommitInventoryCutoverOpeningState::class)($cutover, $demo);
            $this->fail('Committing with an unapproved negative-opening exception must be refused.');
        } catch (DomainInvariantException) {
        }
        $this->assertSame(0, NegativeStockShortage::query()->where('status', 'OPEN_CUTOVER_SHORTAGE')->count());

        // The demo login did not request it, so it may approve it — the live step this data leaves open.
        app(ApproveInventoryCutoverNegativeOpening::class)($this->exception('demo-cutover-fifo-short'), $demo);
        app(CommitInventoryCutoverOpeningState::class)($cutover->refresh(), $demo);

        $this->assertSame(2, NegativeStockShortage::query()->where('status', 'OPEN_CUTOVER_SHORTAGE')->count());
        $avco = Item::query()->where('code', 'demo-cutover-avco-short')->sole();
        $this->assertSame('-25.0000', AvcoPool::query()->where('item_id', $avco->id)->sole()->qty_on_hand);
    }

    private function exception(string $itemCode): InventoryCutoverNegativeOpening
    {
        return InventoryCutoverNegativeOpening::query()
            ->where('item_id', Item::query()->where('code', $itemCode)->sole()->id)->sole();
    }
}
