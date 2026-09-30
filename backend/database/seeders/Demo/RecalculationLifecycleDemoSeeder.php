<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Exceptions\DomainInvariantException;
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
 *   E. `demo-recalc-primer` — AVCO, HIGH_RISK. Its impact (a 200 kg receipt proven to have arrived
 *      earlier) clears the company's high-risk threshold, so one approval is not enough: the
 *      supervisor has given the first, and the second must come from someone with
 *      `inventory.recalculation.approve-high-risk` — the demo login, live.
 *   F. `demo-recalc-sealant` — AVCO, HIGH_RISK, regeneration PAUSED. Three times an approval was
 *      given and then a later stock movement changed the history the impact rests on, so the
 *      next decision found it stale and had to regenerate. After the third the request stops
 *      regenerating on its own and waits for a reviewer to resume it
 *      (`inventory.recalculation.review-regeneration`) — the demo login, live.
 *
 * The company's high-risk threshold is set to 10,000 (base currency) once, and only if the
 * company has none: E and F are large enough to clear it, while the older scenarios (all
 * under 2,500) stay ordinary risk. A version's risk is re-read at every decision, so leaving
 * the threshold in place is what keeps E's second approval live.
 *
 * Approvals expire after the company's TTL, which would quietly turn "approve/execute it live"
 * into a refusal a day after seeding. So each run renews, with the same approver, a live
 * request's lapsed approval (a same-actor renewal appends a fresh row, per ADR 0040).
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

        $this->ensureHighRiskThreshold();
        $this->seedHighRiskAwaitingSecondApprover();
        $this->seedPausedRegeneration();
        $this->renewLapsedApprovals();
    }

    private function ensureHighRiskThreshold(): void
    {
        $company = Company::query()->findOrFail(1);
        if ($company->recalculation_high_risk_threshold_base_amount === null) {
            $company->update(['recalculation_high_risk_threshold_base_amount' => '10000.0000']);
        }
    }

    /** E: as A, ten times the size, first approval given, second (elevated) approver outstanding. */
    private function seedHighRiskAwaitingSecondApprover(): void
    {
        if ($this->exists(15)) {
            return;
        }

        $item = $this->avcoItem('demo-recalc-primer', 'Zinc Primer', 'Dedicated to the recalculation demo. A 200 kg backdated receipt is large enough to be HIGH_RISK: it needs two independent approvers, and only the first has decided.');
        $this->history($item, 100, 15, 10);
        $version = $this->requestBackdatedReceipt($item, 15, '200.0000', '120.0000');
        $this->approve($version, 'Delivery note matches the supplier invoice; second approval required for a high-risk impact.');
    }

    /** F: as E, but three approvals were each invalidated by later stock movements, pausing regeneration. */
    private function seedPausedRegeneration(): void
    {
        if ($this->exists(16)) {
            return;
        }

        $item = $this->avcoItem('demo-recalc-sealant', 'Concrete Sealant', 'Dedicated to the recalculation demo. Stock kept moving while this request was being approved: three approvals were each invalidated, so automatic regeneration stopped and a reviewer must resume it.');
        $this->history($item, 100, 16, 10);
        $this->requestBackdatedReceipt($item, 16, '200.0000', '120.0000');
        $request = RecalculationRequest::query()->where('source_type', self::SOURCE)->where('source_id', 16)->sole();
        $second = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        for ($i = 1; $i <= 3; $i++) {
            $this->approve($request->currentImpactVersion(), 'Approved on the figures then in front of me.');
            // A later receipt changes the history the impact rests on...
            $this->postReceipt($item, $this->location, $this->currency, '1.0000', '100.0000', self::SOURCE.'_LATER', 160 + $i, now()->subDays(14 - $i));
            // ...so the next decision finds it stale, regenerates it, and refuses to decide on old numbers.
            try {
                app(DecideRecalculationApproval::class)($request->currentImpactVersion() ?? $request->impactVersions()->latest('id')->first(), RecalculationApprovalDecision::Approved, 'Second approval.', $second);
            } catch (DomainInvariantException) {
            }
        }
    }

    /** An approval lapses after the company TTL; renew the supervisor's on a live request so the demo step still works. */
    private function renewLapsedApprovals(): void
    {
        foreach ([['DEMO_RECALC_BACKDATED_RECEIPT', 1], [self::SOURCE, 15]] as [$type, $id]) {
            $request = RecalculationRequest::query()->where('source_type', $type)->where('source_id', $id)->first();
            $version = $request?->execution === null ? $request?->currentImpactVersion() : null;
            if ($version === null || $version->approvals()->doesntExist() || $version->unexpiredApprovedActorIds()->contains($this->supervisor->id)) {
                continue;
            }
            $this->approve($version, 'Approval renewed: the earlier one lapsed after the company approval window.');
        }
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
    private function history(Item $item, int $cost, int $scenario, int $scale = 1): void
    {
        $this->postReceipt($item, $this->location, $this->currency, number_format(40 * $scale, 4, '.', ''), number_format($cost, 4, '.', ''), self::SOURCE.'_RECEIPT', $scenario * 10 + 1, now()->subDays(30));
        $this->issue($item, number_format(10 * $scale, 4, '.', ''), $scenario * 10 + 2, now()->subDays(14));
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
