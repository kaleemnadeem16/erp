<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class ItemSupplierApiTest extends TestCase
{
    use RefreshDatabase;

    public function actingAs(UserContract $user, $guard = null): static
    {
        PurchasingActor::grant($user, ['purchasing.item-supplier.manage']);

        return parent::actingAs($user, $guard);
    }

    protected function actingAsUnprivileged(UserContract $user, $guard = null): static
    {
        return parent::actingAs($user, $guard);
    }

    private Item $item;

    private Uom $uom;

    private ChartOfAccount $payable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uom = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->item = Item::create(['code' => 'PIG', 'name' => 'Pigment', 'type' => 'RAW', 'stock_uom_id' => $this->uom->id, 'category_id' => $category->id]);
        $this->payable = ChartOfAccount::create(['code' => '2100', 'name' => 'Payables', 'type' => AccountType::Liability]);
    }

    private function supplier(string $code, ?int $leadTime = null): SupplierAccount
    {
        $party = Party::create(['code' => $code, 'name' => $code]);

        return SupplierAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'lead_time_days' => $leadTime, 'payable_account_id' => $this->payable->id]);
    }

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    public function test_item_supplier_reads_and_writes_require_capabilities(): void
    {
        $user = User::factory()->create();
        $supplier = $this->supplier('READ');
        $relationship = ItemSupplier::create([
            'item_id' => $this->item->id,
            'supplier_account_id' => $supplier->id,
            'priority' => 2,
        ]);

        $this->actingAsUnprivileged($user)->getJson('/api/v1/item-suppliers')->assertForbidden();
        $this->getJson("/api/v1/item-suppliers/{$relationship->id}")->assertForbidden();
        $this->postJson('/api/v1/item-suppliers', [])->assertForbidden();
        $this->patchJson("/api/v1/item-suppliers/{$relationship->id}", ['priority' => 3])->assertForbidden();
        $this->deleteJson("/api/v1/item-suppliers/{$relationship->id}")->assertForbidden();

        PurchasingActor::grant($user, ['purchasing.item-supplier.view']);
        $this->getJson('/api/v1/item-suppliers')->assertOk();
        $this->getJson("/api/v1/item-suppliers/{$relationship->id}")->assertOk();
        $this->postJson('/api/v1/item-suppliers', [])->assertForbidden();
    }

    public function test_multiple_suppliers_and_sourcing_terms_round_trip(): void
    {
        $user = User::factory()->create();
        $preferred = $this->supplier('SUP-A', 14);
        $alternative = $this->supplier('SUP-B', 21);

        $this->actingAs($user)->postJson('/api/v1/item-suppliers', [
            'item_id' => $this->item->id, 'supplier_account_id' => $preferred->id,
            'supplier_item_code' => 'V-PIG', 'priority' => 1, 'minimum_order_qty' => '25.0000',
            'purchase_uom_id' => $this->uom->id,
        ])->assertCreated()->assertJsonPath('data.resolved_lead_time_days', 14)
            ->assertJsonPath('data.minimum_order_qty', '25.0000');

        $this->postJson('/api/v1/item-suppliers', [
            'item_id' => $this->item->id, 'supplier_account_id' => $alternative->id,
            'priority' => 2, 'lead_time_days' => 5,
        ])->assertCreated()->assertJsonPath('data.resolved_lead_time_days', 5);

        $this->getJson('/api/v1/item-suppliers?filter[item_id]='.$this->item->id)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_one_active_preferred_supplier_is_allowed(): void
    {
        $user = User::factory()->create();
        ItemSupplier::create(['item_id' => $this->item->id, 'supplier_account_id' => $this->supplier('SUP-A')->id, 'priority' => 1]);

        $this->actingAs($user)->postJson('/api/v1/item-suppliers', [
            'item_id' => $this->item->id, 'supplier_account_id' => $this->supplier('SUP-B')->id, 'priority' => 1,
        ])->assertUnprocessable();
    }

    public function test_priority_cannot_exceed_postgres_smallint_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/item-suppliers', [
            'item_id' => $this->item->id,
            'supplier_account_id' => $this->supplier('SUP-A')->id,
            'priority' => 40000,
        ])->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_destroy_archives_the_relationship(): void
    {
        $user = User::factory()->create();
        $row = ItemSupplier::create(['item_id' => $this->item->id, 'supplier_account_id' => $this->supplier('SUP-A')->id]);
        $this->actingAs($user)->deleteJson("/api/v1/item-suppliers/{$row->id}")->assertNoContent();
        $this->assertFalse($row->refresh()->active);
    }

    public function test_relationship_identity_cannot_be_repointed_after_creation(): void
    {
        $user = User::factory()->create();
        $originalSupplier = $this->supplier('SUP-A');
        $replacementSupplier = $this->supplier('SUP-B');
        $row = ItemSupplier::create([
            'item_id' => $this->item->id,
            'supplier_account_id' => $originalSupplier->id,
        ]);

        $this->actingAs($user)->patchJson("/api/v1/item-suppliers/{$row->id}", [
            'supplier_account_id' => $replacementSupplier->id,
            'supplier_item_code' => 'UPDATED-CODE',
        ])->assertOk();

        $row->refresh();
        $this->assertSame($originalSupplier->id, $row->supplier_account_id);
        $this->assertSame('UPDATED-CODE', $row->supplier_item_code);

        $this->expectException(RuntimeException::class);
        $row->update(['supplier_account_id' => $replacementSupplier->id]);
    }

    public function test_supplier_reference_cannot_cross_company_boundary(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompany);
        $otherPayable = ChartOfAccount::create(['code' => '2100', 'name' => 'Payables', 'type' => AccountType::Liability]);
        $otherParty = Party::create(['code' => 'OTHER', 'name' => 'Other']);
        $otherSupplier = SupplierAccount::create(['party_id' => $otherParty->id, 'code' => 'OTHER', 'payment_terms_days' => 30, 'payable_account_id' => $otherPayable->id]);
        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        ItemSupplier::create(['item_id' => $this->item->id, 'supplier_account_id' => $otherSupplier->id]);
    }

    public function test_index_supports_advanced_search_filters_and_sorting(): void
    {
        $user = User::factory()->create();
        $partyA = Party::create(['code' => 'SUP-ALPHA', 'name' => 'Acme Chemicals Inc']);
        $supplierA = SupplierAccount::create([
            'party_id' => $partyA->id,
            'code' => 'SUP-ALPHA',
            'payment_terms_days' => 30,
            'lead_time_days' => 7,
            'payable_account_id' => $this->payable->id,
        ]);

        $partyB = Party::create(['code' => 'SUP-BETA', 'name' => 'Beta Logistics Ltd']);
        $supplierB = SupplierAccount::create([
            'party_id' => $partyB->id,
            'code' => 'SUP-BETA',
            'payment_terms_days' => 30,
            'lead_time_days' => 21,
            'payable_account_id' => $this->payable->id,
        ]);

        $relA = ItemSupplier::create([
            'item_id' => $this->item->id,
            'supplier_account_id' => $supplierA->id,
            'supplier_item_code' => 'ACM-999',
            'priority' => 1,
            'lead_time_days' => 5,
            'minimum_order_qty' => '100.0000',
            'active' => true,
        ]);

        $relB = ItemSupplier::create([
            'item_id' => $this->item->id,
            'supplier_account_id' => $supplierB->id,
            'supplier_item_code' => 'BET-111',
            'priority' => 2,
            'lead_time_days' => 25,
            'minimum_order_qty' => '500.0000',
            'active' => false,
        ]);

        // Search by party name
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?filter[search]=Acme')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($relA->id, $res->json('data.0.id'));

        // Search by supplier item code
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?filter[search]=BET-111')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($relB->id, $res->json('data.0.id'));

        // Filter by supplier_item_code
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?filter[supplier_item_code]=ACM')->assertOk();
        $this->assertCount(1, $res->json('data'));

        // Filter by lead time range
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?filter[lead_time_min]=10')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($relB->id, $res->json('data.0.id'));

        // Filter by MOQ range
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?filter[moq_max]=200')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($relA->id, $res->json('data.0.id'));

        // Sort by minimum_order_qty desc
        $res = $this->actingAs($user)->getJson('/api/v1/item-suppliers?sort=-minimum_order_qty')->assertOk();
        $this->assertSame($relB->id, $res->json('data.0.id'));
        $this->assertSame($relA->id, $res->json('data.1.id'));

        // Includable supplierAccount loads party
        $res = $this->actingAs($user)->getJson("/api/v1/item-suppliers/{$relA->id}?include=supplierAccount")->assertOk();
        $this->assertSame('Acme Chemicals Inc', $res->json('data.supplier_account.party.name'));
    }
}
