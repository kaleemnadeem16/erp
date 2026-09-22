<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierSiteApiTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAccount $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $payableAccount = ChartOfAccount::create(['code' => '2100', 'name' => 'Accounts Payable', 'type' => AccountType::Liability]);
        $this->supplier = SupplierAccount::create([
            'code' => 'ACME', 'party_id' => $party->id,
            'payment_terms_days' => 30, 'payable_account_id' => $payableAccount->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main Warehouse',
            'address_line1' => '12 Industrial Road',
            'city' => 'Lahore',
            'state' => 'Punjab',
            'postal_code' => '54000',
            'country_code' => 'pk',
            'role' => 'ORDER_FROM',
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites")->assertUnauthorized();
    }

    public function test_a_site_can_be_created_and_listed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.role', 'ORDER_FROM');
        $response->assertJsonPath('data.is_default', false);
        $response->assertJsonPath('data.party_site.name', 'Main Warehouse');
        $response->assertJsonPath('data.party_site.city', 'Lahore');
        // country_code is uppercased regardless of what was typed.
        $response->assertJsonPath('data.party_site.country_code', 'PK');
        // A derived, unique code — never asked for on the form.
        $this->assertNotEmpty($response->json('data.party_site.code'));

        $this->actingAs($user)
            ->getJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.party_site.name', 'Main Warehouse');
    }

    public function test_a_second_default_of_the_same_role_displaces_the_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload(['is_default' => true]))->json('data.id');
        $second = $this->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload(['name' => 'Second Site', 'is_default' => true]))->json('data.id');

        $sites = collect($this->getJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites")->json('data'))->keyBy('id');
        $this->assertFalse($sites[$first]['is_default']);
        $this->assertTrue($sites[$second]['is_default']);
    }

    public function test_updating_address_and_role_fields_together(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload())->json('data.id');

        $response = $this->patchJson("/api/v1/supplier-sites/{$id}", [
            'city' => 'Karachi',
            'role' => 'SHIP_FROM',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.party_site.city', 'Karachi');
        $response->assertJsonPath('data.role', 'SHIP_FROM');
        // Untouched fields survive the partial update.
        $response->assertJsonPath('data.party_site.name', 'Main Warehouse');
    }

    public function test_deleting_a_site_removes_it_from_the_list_but_keeps_the_address_record(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/supplier-sites/{$id}")->assertNoContent();

        $this->getJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('party_sites', ['name' => 'Main Warehouse']);
        $this->assertSoftDeleted('supplier_sites', ['id' => $id]);
    }

    public function test_a_site_cannot_be_created_for_another_companys_supplier(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherParty = Party::create(['code' => 'OTHER', 'name' => 'Other Party']);
        $otherPayable = ChartOfAccount::create(['code' => '2200', 'name' => 'Other Payables', 'type' => AccountType::Liability]);
        $otherSupplier = SupplierAccount::create([
            'code' => 'OTHER', 'party_id' => $otherParty->id,
            'payment_terms_days' => 30, 'payable_account_id' => $otherPayable->id,
        ]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/supplier-accounts/{$otherSupplier->id}/sites", $this->payload())
            ->assertNotFound();
    }

    public function test_role_must_be_a_known_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/supplier-accounts/{$this->supplier->id}/sites", $this->payload(['role' => 'BILL_TO']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }
}
