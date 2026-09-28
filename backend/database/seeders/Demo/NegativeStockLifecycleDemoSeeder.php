<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\ReviewNegativeStockOverride;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * The later stages of ADR 0037's negative-stock workflow. StockDemoSeeder
 * deliberately leaves its two override requests undecided so the demo login
 * can take the next step itself; that shows the *pending* states only. Nothing
 * there is ever approved, posted, reviewed or settled, so a list filter, an
 * open-shortage view or a settlement history would be empty until someone
 * happened to drive a whole workflow by hand. These scenarios add one record
 * in each remaining state, all produced through the same Actions the app
 * uses, and leave StockDemoSeeder's two pending requests exactly as they are.
 *
 * Each scenario owns a dedicated item, for two reasons: an exact
 * (item, location, batch) can carry only one open shortage, and nothing else
 * seeded (or exercised by the e2e specs, which use demo-pig-blue) should
 * change because a shortage was created.
 *
 *   A. `demo-neg-primer` — PRIOR_APPROVAL, both outcomes of the decision. A first
 *      request is REJECTED (the delivery was due the next day); a second is APPROVED and
 *      posted, leaving an OPEN shortage. Left open on purpose, like StockDemoSeeder's pending
 *      items: receive more primer at Main Stock (the item is purchasable) and watch the
 *      inbound settle it.
 *   B. `demo-neg-hardener` — IMMEDIATE_WITH_REVIEW, reviewed JUSTIFIED, then PARTIALLY_SETTLED
 *      by an inbound smaller than the shortage. The inbound costs more than the provisional
 *      cost the shortage was posted at, so the settlement carries a valuation adjustment.
 *   C. `demo-neg-sealant` — IMMEDIATE_WITH_REVIEW, reviewed FLAGGED, then fully SETTLED by an
 *      inbound larger than the shortage; the excess becomes ordinary stock.
 *
 * Together with the two pending requests this covers: awaiting a decision, rejected,
 * approved, awaiting review, reviewed justified, reviewed flagged, and shortages that are
 * open, partially settled and settled.
 *
 * Idempotency: a request, decision, review, movement and settlement are all immutable, so
 * each scenario is *created once and recognised-and-skipped* by its request's natural key
 * (source type + id), never recomputed from live stock — the shape ADR-level guidance in
 * docs/architecture/environments.md ("Immutable transactional demo data") requires. A user
 * who later settles scenario A's shortage by hand therefore does not break a rerun.
 */
final class NegativeStockLifecycleDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const SOURCE = 'DEMO_NEG_STOCK';

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
        // StockDemoSeeder syncs this account to its own permission list on every run, so the
        // grants are (re)applied here, after it, rather than edited into that list.
        $this->supervisor->givePermissionTo(['inventory.negative-stock.approve', 'inventory.negative-stock.review']);

        $this->location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $this->currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        $company = Company::query()->findOrFail(1);
        // A mutable company setting, safe to reconcile every run (unlike the immutable rows below).
        $company->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE']);

        $this->seedPrimer($company);
        $this->seedHardener($company);
        $this->seedSealant($company);

        // The resting policy StockDemoSeeder leaves, whichever scenarios actually ran this time.
        $company->update(['negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW']);
    }

    /** A: a rejected request, then an approved and posted one that leaves an open shortage. */
    private function seedPrimer(Company $company): void
    {
        if ($this->exists(1)) {
            return;
        }

        $item = $this->item(
            'demo-neg-primer', 'Zinc Phosphate Primer', true,
            'Dedicated to the negative-stock demo. A job issue exceeded the 20 kg on hand: the first override request was rejected, the second approved and posted, leaving a 10 kg open shortage until the next receipt.',
        );
        $this->receive($item, '20.0000', '400.0000', 1);

        $company->update(['negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);

        $rejected = $this->request($item, 1, '40.0000', 'LATE_RECEIPT', 'Rush repaint needs 40 kg today; the supplier delivery is only due tomorrow.');
        app(DecideNegativeStockOverride::class)(
            $rejected, NegativeStockApprovalDecision::Rejected,
            'The delivery arrives tomorrow morning — reschedule the job rather than issue stock that is not here.', $this->supervisor,
        );

        $approved = $this->request($item, 2, '30.0000', 'OPERATIONAL_EMERGENCY', 'Corrosion-protection coat cannot wait; 20 kg on hand, 30 kg needed for the shift.');
        app(DecideNegativeStockOverride::class)(
            $approved, NegativeStockApprovalDecision::Approved,
            'Safety-critical coat; the purchase order is confirmed for delivery this week.', $this->supervisor,
        );
        $this->issue($item, $approved, '30.0000');
    }

    /** B: immediate override, reviewed justified, partially settled at a higher actual cost. */
    private function seedHardener(Company $company): void
    {
        if ($this->exists(3)) {
            return;
        }

        $item = $this->item(
            'demo-neg-hardener', 'Epoxy Hardener', false,
            'Dedicated to the negative-stock demo. Issued 15 kg beyond stock under an immediate override, reviewed as justified, then part-settled by an inbound that cost more than the provisional cost the shortage was posted at.',
        );
        $this->receive($item, '10.0000', '250.0000', 3);

        $company->update(['negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW']);

        $request = $this->request($item, 3, '25.0000', 'PAPERWORK_DELAY', 'Hardener was physically received but the delivery note is delayed.');
        $this->issue($item, $request, '25.0000');
        app(ReviewNegativeStockOverride::class)(
            $request, NegativeStockReviewOutcome::Justified,
            'Delivery note later matched to the physical receipt; the issue was legitimate.', $this->supervisor,
        );

        // 10 kg of the 15 kg shortage, at 275 against the 250 provisional cost: PARTIALLY_SETTLED
        // with a valuation adjustment for the difference.
        $this->receive($item, '10.0000', '275.0000', 4);
    }

    /** C: immediate override, reviewed flagged, then fully settled with the excess kept as stock. */
    private function seedSealant(Company $company): void
    {
        if ($this->exists(5)) {
            return;
        }

        $item = $this->item(
            'demo-neg-sealant', 'Joint Sealant', false,
            'Dedicated to the negative-stock demo. Issued 4 kg beyond stock under an immediate override that the review flagged, then fully settled by an inbound larger than the shortage — the excess is ordinary stock.',
        );
        $this->receive($item, '8.0000', '120.0000', 5);

        $company->update(['negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW']);

        $request = $this->request($item, 5, '12.0000', 'OPERATIONAL_EMERGENCY', 'Leak repair used more sealant than the 8 kg on hand.');
        $this->issue($item, $request, '12.0000');
        app(ReviewNegativeStockOverride::class)(
            $request, NegativeStockReviewOutcome::Flagged,
            'The emergency use was not logged against a job — follow up with the site supervisor.', $this->supervisor,
        );

        // 6 kg against the 4 kg shortage: SETTLED, and the remaining 2 kg stays in stock.
        $this->receive($item, '6.0000', '130.0000', 6);
    }

    private function exists(int $sourceId): bool
    {
        return NegativeStockOverrideRequest::query()->where('source_type', self::SOURCE)->where('source_id', $sourceId)->exists();
    }

    private function item(string $code, string $name, bool $purchasable, string $description): Item
    {
        $category = Category::query()->where('code', 'demo-solvents')->firstOrFail();
        $item = Item::withTrashed()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name, 'description' => $description, 'type' => ItemType::Raw,
                'stock_uom_id' => Uom::query()->where('code', 'kg')->firstOrFail()->id, 'is_stocked' => true,
                'is_purchasable' => $purchasable, 'is_sellable' => false,
                'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
                'tracking' => ItemTracking::None, 'category_id' => $category->id, 'inventory_account_id' => null, 'custom_fields' => [],
            ],
        );
        $item->restore();
        if ($item->resolveInventoryAccountId() === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id]);
        }

        return $item;
    }

    private function receive(Item $item, string $qty, string $cost, int $sourceId): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $this->location->id, 'qty' => $qty, 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'DEMO_NEG_STOCK_RECEIPT', 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => $this->currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => $cost,
        ]);
    }

    private function request(Item $item, int $sourceId, string $qty, string $reason, string $explanation): NegativeStockOverrideRequest
    {
        return app(RequestNegativeStockOverride::class)([
            'item_id' => $item->id, 'location_id' => $this->location->id, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => $qty, 'source_type' => self::SOURCE, 'source_id' => $sourceId,
            'reason_code' => $reason, 'explanation' => $explanation,
        ], $this->requester);
    }

    private function issue(Item $item, NegativeStockOverrideRequest $request, string $qty): void
    {
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $this->location->id, 'qty' => $qty, 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => self::SOURCE, 'source_id' => $request->source_id,
            'override_request_id' => $request->id,
        ]);
    }
}
