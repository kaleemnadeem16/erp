<?php

namespace Tests\Feature\Modules\Accounting;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\CreateCategory;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\UpdateCategory;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Actions\CreateCustomerAccount;
use App\Modules\Parties\Actions\CreateSupplierAccount;
use App\Modules\Parties\Actions\UpdateCustomerAccount;
use App\Modules\Parties\Actions\UpdateSupplierAccount;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class FinancialMasterDataAccessTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $ledger;

    private Category $category;

    private Item $item;

    private Party $party;

    private CustomerAccount $customer;

    private SupplierAccount $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = ChartOfAccount::create(['code' => 'FINANCE', 'name' => 'Finance account', 'type' => 'ASSET']);
        $this->category = Category::create(['code' => 'RAW', 'name' => 'Raw category', 'income_account_id' => $this->ledger->id, 'expense_account_id' => $this->ledger->id, 'inventory_account_id' => $this->ledger->id]);
        $uom = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'MASS', 'factor_to_base' => '1', 'is_base' => true]);
        $this->item = Item::create(['code' => 'RAW-1', 'name' => 'Raw item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'valuation_method' => 'AVCO', 'category_id' => $this->category->id, 'income_account_id' => $this->ledger->id, 'expense_account_id' => $this->ledger->id, 'inventory_account_id' => $this->ledger->id]);
        $this->party = Party::create(['code' => 'PARTNER', 'name' => 'Partner']);
        $this->customer = CustomerAccount::create(['code' => 'CUSTOMER', 'party_id' => $this->party->id, 'credit_limit' => '54321.0000', 'payment_terms_days' => 30, 'receivable_account_id' => $this->ledger->id]);
        $this->supplier = SupplierAccount::create(['code' => 'SUPPLIER', 'party_id' => $this->party->id, 'payment_terms_days' => 60, 'payable_account_id' => $this->ledger->id]);
    }

    public function test_ordinary_readers_never_receive_credit_or_mappings_in_lists_details_and_includes(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            '/items?include=category', "/items/{$this->item->id}?include=category",
            '/categories?include=parent,children', "/categories/{$this->category->id}?include=parent,children",
            '/parties?include=customer_account,supplier_account', "/parties/{$this->party->id}?include=customer_account,supplier_account",
            '/customer-accounts?include=party', "/customer-accounts/{$this->customer->id}?include=party",
            '/supplier-accounts?include=party', "/supplier-accounts/{$this->supplier->id}?include=party",
        ] as $path) {
            $response = $this->getJson('/api/v1'.$path)->assertOk();
            $this->assertRestrictedFieldsMissing($response->json('data'));
            $this->assertStringNotContainsString('54321.0000', $response->getContent());
        }
        $this->getJson("/api/v1/customer-accounts/{$this->customer->id}")->assertJsonPath('data.payment_terms_days', 30);
        $this->getJson("/api/v1/supplier-accounts/{$this->supplier->id}")->assertJsonPath('data.payment_terms_days', 60);
        $this->getJson('/api/v1/items?filter[inventory_account_id]='.$this->ledger->id)->assertUnprocessable();
        $this->getJson('/api/v1/customer-accounts?sort=credit_limit')->assertUnprocessable();
    }

    public function test_cross_module_item_and_supplier_includes_cannot_bypass_mapping_projection(): void
    {
        $offer = ItemSupplier::create(['item_id' => $this->item->id, 'supplier_account_id' => $this->supplier->id]);
        $reader = PermissionedActor::grant(User::factory()->create(), ['purchasing.item-supplier.manage']);
        $this->actingAs($reader);
        foreach (['/api/v1/item-suppliers?include=item,supplierAccount', "/api/v1/item-suppliers/{$offer->id}?include=item,supplierAccount"] as $path) {
            $response = $this->getJson($path)->assertOk();
            $this->assertRestrictedFieldsMissing($response->json('data'));
        }
        PermissionedActor::grant($reader, ['accounting.account-mapping.view']);
        $this->getJson("/api/v1/item-suppliers/{$offer->id}?include=item,supplierAccount")->assertOk()
            ->assertJsonPath('data.item.inventory_account_id', $this->ledger->id)
            ->assertJsonPath('data.supplier_account.payable_account_id', $this->ledger->id);
    }

    public function test_financial_field_read_capabilities_cannot_cross_company_boundaries(): void
    {
        $reader = PermissionedActor::grant(User::factory()->create(), ['accounting.account-mapping.view', 'parties.credit-limit.view']);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $original = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $ledger = ChartOfAccount::create(['code' => 'FOREIGN', 'name' => 'Foreign ledger', 'type' => 'ASSET']);
        $category = Category::create(['code' => 'FOREIGN', 'name' => 'Foreign category', 'inventory_account_id' => $ledger->id]);
        $party = Party::create(['code' => 'FOREIGN', 'name' => 'Foreign partner']);
        $customer = CustomerAccount::create(['code' => 'FOREIGN', 'party_id' => $party->id, 'credit_limit' => '98765.0000', 'payment_terms_days' => 30, 'receivable_account_id' => $ledger->id]);
        app()->instance(CompanyContext::class, $original);
        $this->actingAs($reader)->getJson("/api/v1/customer-accounts/{$customer->id}")->assertNotFound();
        $this->getJson("/api/v1/categories/{$category->id}")->assertNotFound();
        foreach (['/api/v1/customer-accounts?include=party', '/api/v1/parties?include=customer_account', '/api/v1/categories?include=children'] as $path) {
            $response = $this->getJson($path)->assertOk();
            $this->assertStringNotContainsString('98765.0000', $response->getContent());
            $this->assertStringNotContainsString('FOREIGN', $response->getContent());
        }
    }

    public function test_credit_and_mapping_readers_have_independent_field_projections(): void
    {
        $credit = PermissionedActor::grant(User::factory()->create(), ['parties.credit-limit.view']);
        $mapping = PermissionedActor::grant(User::factory()->create(), ['accounting.account-mapping.view']);
        $this->actingAs($credit)->getJson("/api/v1/parties/{$this->party->id}?include=customer_account,supplier_account")->assertOk()
            ->assertJsonPath('data.customer_account.credit_limit', '54321.0000')
            ->assertJsonMissingPath('data.customer_account.receivable_account_id')
            ->assertJsonMissingPath('data.supplier_account.payable_account_id');
        $this->actingAs($mapping)->getJson("/api/v1/parties/{$this->party->id}?include=customer_account,supplier_account")->assertOk()
            ->assertJsonMissingPath('data.customer_account.credit_limit')
            ->assertJsonPath('data.customer_account.receivable_account_id', $this->ledger->id)
            ->assertJsonPath('data.supplier_account.payable_account_id', $this->ledger->id);
        $this->getJson("/api/v1/items/{$this->item->id}?include=category")->assertOk()
            ->assertJsonPath('data.inventory_account_id', $this->ledger->id)
            ->assertJsonPath('data.category.inventory_account_id', $this->ledger->id);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.income_account_id', $this->ledger->id);
        $this->actingAs($credit)->patchJson("/api/v1/customer-accounts/{$this->customer->id}", ['credit_limit' => '1'])->assertForbidden();
        $this->actingAs($mapping)->patchJson("/api/v1/items/{$this->item->id}", ['inventory_account_id' => null])->assertForbidden();
    }

    public function test_ordinary_maintainers_can_edit_operational_data_but_cannot_set_clear_or_reassert_financial_fields(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage', 'inventory.category.manage', 'parties.customer-account.manage', 'parties.supplier-account.manage']);
        $this->actingAs($actor);
        foreach ([
            ["items/{$this->item->id}", 'inventory_account_id', $this->ledger->id],
            ["categories/{$this->category->id}", 'income_account_id', $this->ledger->id],
            ["customer-accounts/{$this->customer->id}", 'receivable_account_id', $this->ledger->id],
            ["supplier-accounts/{$this->supplier->id}", 'payable_account_id', $this->ledger->id],
            ["customer-accounts/{$this->customer->id}", 'credit_limit', '54321.0000'],
            ["customer-accounts/{$this->customer->id}", 'payment_terms_days', 30],
            ["supplier-accounts/{$this->supplier->id}", 'payment_terms_days', 60],
        ] as [$path, $field, $value]) {
            foreach ([$value, null] as $attempt) {
                $this->patchJson('/api/v1/'.$path, [$field => $attempt])->assertForbidden()->assertJsonMissingPath('data.'.$field);
            }
        }
        $this->patchJson("/api/v1/items/{$this->item->id}", ['name' => 'New name'])->assertOk()->assertJsonMissingPath('data.inventory_account_id');
        $this->patchJson("/api/v1/categories/{$this->category->id}", ['name' => 'New category'])->assertOk()->assertJsonMissingPath('data.inventory_account_id');
        $this->patchJson("/api/v1/customer-accounts/{$this->customer->id}", ['code' => 'NEW-CUSTOMER'])->assertOk()->assertJsonMissingPath('data.credit_limit');
        $this->assertSame('54321.0000', $this->customer->fresh()->credit_limit);
        $this->assertSame($this->ledger->id, $this->item->fresh()->inventory_account_id);
        $this->assertSame(30, $this->customer->fresh()->payment_terms_days);
    }

    public function test_individual_field_managers_can_change_their_fields_and_read_the_result_without_other_field_powers(): void
    {
        $credit = PermissionedActor::grant(User::factory()->create(), ['parties.customer-account.manage', 'parties.credit-limit.manage']);
        $this->actingAs($credit)->patchJson("/api/v1/customer-accounts/{$this->customer->id}", ['credit_limit' => '100.0000'])->assertOk()
            ->assertJsonPath('data.credit_limit', '100.0000')->assertJsonMissingPath('data.receivable_account_id');
        $this->patchJson("/api/v1/customer-accounts/{$this->customer->id}", ['payment_terms_days' => 10])->assertForbidden();
        $terms = PermissionedActor::grant(User::factory()->create(), ['parties.customer-account.manage', 'parties.supplier-account.manage', 'parties.payment-terms.manage']);
        $this->actingAs($terms)->patchJson("/api/v1/customer-accounts/{$this->customer->id}", ['payment_terms_days' => 10])->assertOk()
            ->assertJsonPath('data.payment_terms_days', 10)->assertJsonMissingPath('data.credit_limit');
        $this->patchJson("/api/v1/supplier-accounts/{$this->supplier->id}", ['payment_terms_days' => 20])->assertOk()->assertJsonMissingPath('data.payable_account_id');
        $mapping = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage', 'inventory.category.manage', 'accounting.account-mapping.manage']);
        $this->actingAs($mapping)->patchJson("/api/v1/items/{$this->item->id}", ['inventory_account_id' => null])->assertOk()->assertJsonPath('data.inventory_account_id', null);
        $this->patchJson("/api/v1/categories/{$this->category->id}", ['inventory_account_id' => null])->assertOk()->assertJsonPath('data.inventory_account_id', null);
    }

    public function test_all_eight_write_actions_enforce_field_authority_without_http(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage', 'inventory.category.manage', 'parties.customer-account.manage', 'parties.supplier-account.manage']);
        foreach ([
            fn () => app(CreateItem::class)(['income_account_id' => null], $actor),
            fn () => app(UpdateItem::class)($this->item, ['income_account_id' => null], $actor),
            fn () => app(CreateCategory::class)(['inventory_account_id' => null], $actor),
            fn () => app(UpdateCategory::class)($this->category, ['inventory_account_id' => null], $actor),
            fn () => app(CreateCustomerAccount::class)(['receivable_account_id' => $this->ledger->id], $actor),
            fn () => app(UpdateCustomerAccount::class)($this->customer, ['credit_limit' => null], $actor),
            fn () => app(CreateSupplierAccount::class)(['payment_terms_days' => 30], $actor),
            fn () => app(UpdateSupplierAccount::class)($this->supplier, ['payment_terms_days' => 30], $actor),
        ] as $write) {
            try {
                $write();
                $this->fail('Field authority was bypassed by a direct Action caller.');
            } catch (DomainInvariantException $exception) {
                $this->assertStringContainsString('not permitted', $exception->getMessage());
            }
        }
        $this->assertSame('54321.0000', $this->customer->fresh()->credit_limit);
        $this->assertSame($this->ledger->id, $this->item->fresh()->income_account_id);
        $this->assertSame(60, $this->supplier->fresh()->payment_terms_days);
    }

    public function test_account_creation_requires_its_mandatory_financial_field_duties(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['parties.customer-account.manage', 'parties.supplier-account.manage']);
        $party = Party::create(['code' => 'NEW', 'name' => 'New party']);
        $customer = ['party_id' => $party->id, 'code' => 'NEW', 'payment_terms_days' => 30, 'receivable_account_id' => $this->ledger->id];
        $supplier = ['party_id' => $party->id, 'code' => 'NEW', 'payment_terms_days' => 60, 'payable_account_id' => $this->ledger->id];
        $this->actingAs($actor)->postJson('/api/v1/customer-accounts', $customer)->assertForbidden();
        $this->postJson('/api/v1/supplier-accounts', $supplier)->assertForbidden();
        PermissionedActor::grant($actor, ['accounting.account-mapping.manage', 'parties.payment-terms.manage']);
        $this->postJson('/api/v1/customer-accounts', $customer)->assertCreated()->assertJsonMissingPath('data.credit_limit');
        $this->postJson('/api/v1/supplier-accounts', $supplier)->assertCreated()->assertJsonPath('data.payable_account_id', $this->ledger->id);
    }

    /** @param array<mixed> $data */
    private function assertRestrictedFieldsMissing(array $data): void
    {
        foreach (['credit_limit', 'income_account_id', 'expense_account_id', 'inventory_account_id', 'receivable_account_id', 'payable_account_id'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->assertRestrictedFieldsMissing($value);
            }
        }
    }
}
