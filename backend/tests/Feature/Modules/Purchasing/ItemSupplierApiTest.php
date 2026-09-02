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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ItemSupplierApiTest extends TestCase
{
    use RefreshDatabase;

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
}
