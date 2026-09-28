<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\PermissionRegistry;
use Carbon\CarbonInterface;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Concerns\SeedsDedicatedStockItems;
use Illuminate\Database\Seeder;

/**
 * The later stages of ADR 0040's backdated-receipt recalculation workflow.
 * StockDemoSeeder leaves exactly one request — approved, not executed — so the
 * demo login can execute it live; every other state, and both shapes of
 * correction, would stay invisible until someone drove a whole workflow by
 * hand. These scenarios add one request in each remaining state, all through
 * the same Actions the app uses, and leave that request exactly as it is.
 *
 * Each scenario owns a dedicated item, because a recalculation targets one
 * specific historical movement and reusing an item would tangle the dates.
 * Every scenario is the same story: a supplier invoice later proves that a
 * receipt happened earlier than it was booked, and a later issue was costed
 * without it.
 *
 *   A. `demo-recalc-titanium` — AVCO, APPROVED then EXECUTED. The AVCO pool is a running
 *      average, so execution posts the backdated receipt truthfully and converges the pool
 *      to the approved counterfactual with one valuation adjustment.
 *   B. `demo-recalc-enamel` — FIFO, APPROVED then EXECUTED. The backdated cheaper layer sits
 *      before the layer the later issue consumed, so execution reallocates the issue with
 *      append-only reversal and replacement entries rather than a pool adjustment.
 *   C. `demo-recalc-binder` — AVCO, REJECTED. The approver decides the "backdating" was a
 *      clerical error, so nothing may be executed.
 *   D. `demo-recalc-stain` — AVCO, left awaiting its first decision.
 *
 * Not covered: a HIGH_RISK request routed to two approvers and a regeneration paused for
 * review need repeated ledger invalidations to construct, which the fixture cost does not
 * justify for demo purposes alone.
 *
 * Idempotency: a request, decision, execution and every movement are immutable, so each
 * scenario is created once and recognised-and-skipped by its request's natural key, never
 * recomputed from live stock (docs/architecture/environments.md, "Immutable transactional
 * demo data").
 */
final class RecalculationLifecycleDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;
    use SeedsDedicatedStockItems;

    private const SOURCE = 'DEMO_RECALC_LIFECYCLE';

    private User $requester;

    private User $supervisor;

    private Location $location;

    private Currency $currency;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        app(PermissionRegistry::class)->registerForTeam(1);

        $this->requester = User::query()->where('email', 'demo-ops@erp.x1techs.com')->firstOrFail();
        $this->supervisor = User::query()->where('email', 'demo-supervisor@erp.x1techs.com')->firstOrFail();
        // StockDemoSeeder syncs this account's permissions on every run, so the grant is
        // (re)applied here, after it, rather than edited into that list.
        $this->supervisor->givePermissionTo('inventory.recalculation.execute');

        $this->location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $this->currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        // Mutable company settings, safe to reconcile every run: without them a backdated
        // receipt is refused outright. StockDemoSeeder sets the same values on its first run.
        Company::query()->findOrFail(1)->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => now()->subYear()->toDateString(),
        ]);

        $this->seedExecutedAvco();
        $this->seedExecutedFifo();
        $this->seedRejected();
        $this->seedAwaitingDecision();
    }

    /** A: 40 kg @100 a month ago, 10 kg issued two weeks ago, then 20 kg @120 proven to have arrived three weeks ago. */
    private function seedExecutedAvco(): void
    {
        if ($this->exists(11)) {
            return;
        }

        $item = $this->avcoItem('demo-recalc-titanium', 'Titanium Dioxide Pigment', 'Dedicated to the recalculation demo. A supplier invoice proved a 20 kg receipt arrived three weeks ago, before a later issue was costed: the backdated receipt was approved and executed, converging the AVCO pool with a valuation adjustment.');
        $this->history($item, 100, 11);
        $version = $this->requestBackdatedReceipt($item, 11, '20.0000', '120.0000');
        $this->approve($version, 'Late paperwork confirmed against the supplier invoice.');
        app(ExecuteRecalculation::class)($version->fresh(), $this->supervisor);
    }

    /** B: FIFO layers 10 @100 and 10 @120, 12 issued, then a cheaper 5 @80 layer proven to sit between them. */
    private function seedExecutedFifo(): void
    {
        if ($this->exists(12)) {
            return;
        }

        $item = $this->dedicatedStockItem(
            'demo-recalc-enamel', 'Marine Enamel Base',
            'Dedicated to the recalculation demo. A cheaper 5 L layer proved to have arrived between two existing layers: the backdated receipt was approved and executed, reallocating the later issue with append-only reversal and replacement entries.',
            method: ValuationMethod::Fifo, scope: null, uomCode: 'l',
        );
        $this->postReceipt($item, $this->location, $this->currency, '10.0000', '100.0000', self::SOURCE.'_RECEIPT', 121, now()->subDays(30));
        $this->postReceipt($item, $this->location, $this->currency, '10.0000', '120.0000', self::SOURCE.'_RECEIPT', 122, now()->subDays(25));
        $this->issue($item, '12.0000', 123, now()->subDays(14));

        $version = $this->requestBackdatedReceipt($item, 12, '5.0000', '80.0000', 28);
        $this->approve($version, 'Delivery note and invoice both dated four weeks ago; layer order corrected.');
        app(ExecuteRecalculation::class)($version->fresh(), $this->supervisor);
    }

    /** C: same shape as A, but the approver rejects it. */
    private function seedRejected(): void
    {
        if ($this->exists(13)) {
            return;
        }

        $item = $this->avcoItem('demo-recalc-binder', 'Acrylic Binder', 'Dedicated to the recalculation demo. A proposed backdated receipt was rejected as a clerical error, so it can never be executed and the ledger is unchanged.');
        $this->history($item, 130, 13);
        $version = $this->requestBackdatedReceipt($item, 13, '20.0000', '120.0000');
        app(DecideRecalculationApproval::class)(
            $version, RecalculationApprovalDecision::Rejected,
            'The supplier confirmed the invoice date was a typing error — there was no earlier receipt.', $this->supervisor,
        );
    }

    /** D: same shape as A, left undecided so the demo login can decide it. */
    private function seedAwaitingDecision(): void
    {
        if ($this->exists(14)) {
            return;
        }

        $item = $this->avcoItem('demo-recalc-stain', 'Wood Stain', 'Dedicated to the recalculation demo. A proposed backdated receipt is still awaiting its first approval decision.');
        $this->history($item, 150, 14);
        $this->requestBackdatedReceipt($item, 14, '20.0000', '120.0000');
    }

    private function exists(int $sourceId): bool
    {
        return RecalculationRequest::query()->where('source_type', self::SOURCE)->where('source_id', $sourceId)->exists();
    }

    private function avcoItem(string $code, string $name, string $description): Item
    {
        return $this->dedicatedStockItem($code, $name, $description);
    }

    /** 40 kg received a month ago at $cost, 10 kg issued two weeks ago — the later movement the backdated receipt conflicts with. */
    private function history(Item $item, int $cost, int $scenario): void
    {
        $this->postReceipt($item, $this->location, $this->currency, '40.0000', number_format($cost, 4, '.', ''), self::SOURCE.'_RECEIPT', $scenario * 10 + 1, now()->subDays(30));
        $this->issue($item, '10.0000', $scenario * 10 + 2, now()->subDays(14));
    }

    private function issue(Item $item, string $qty, int $sourceId, CarbonInterface $movedAt): void
    {
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $this->location->id, 'qty' => $qty, 'uom_id' => $item->stock_uom_id,
            'moved_at' => $movedAt, 'source_type' => self::SOURCE.'_ISSUE', 'source_id' => $sourceId,
        ]);
    }

    private function requestBackdatedReceipt(Item $item, int $sourceId, string $qty, string $cost, int $daysAgo = 21): RecalculationImpactVersion
    {
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $this->location->id, 'qty' => $qty,
            'unit_cost' => $cost, 'currency_id' => $this->currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => $cost,
            'moved_at' => now()->subDays($daysAgo), 'source_type' => self::SOURCE, 'source_id' => $sourceId,
        ], $this->requester);

        return $request->impactVersions->sole();
    }

    private function approve(RecalculationImpactVersion $version, string $notes): void
    {
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, $notes, $this->supervisor);
    }
}
