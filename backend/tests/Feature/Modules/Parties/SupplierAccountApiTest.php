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

class SupplierAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    private ChartOfAccount $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $this->payableAccount = ChartOfAccount::create([
            'code' => '2100', 'name' => 'Accounts Payable', 'type' => AccountType::Liability,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'party_id' => $this->party->id,
            'code' => 'ACME',
            'payment_terms_days' => 30,
            'payable_account_id' => $this->payableAccount->id,
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/supplier-accounts')->assertUnauthorized();
    }

    public function test_a_supplier_account_can_be_created_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/supplier-accounts', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'ACME');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/supplier-accounts/{$id}")->assertOk()->assertJsonPath('data.payment_terms_days', 30);
    }

    public function test_a_nonexistent_payable_account_id_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/supplier-accounts', $this->payload(['payable_account_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');
    }

    public function test_lead_time_days_outside_the_smallint_range_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/supplier-accounts', $this->payload(['lead_time_days' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lead_time_days');
    }

    public function test_a_party_can_only_hold_one_supplier_account_duplicate_is_rejected_with_a_422(): void
    {
        $user = User::factory()->create();
        SupplierAccount::create($this->payload());

        $this->actingAs($user)->postJson('/api/v1/supplier-accounts', $this->payload(['code' => 'ACME-2']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
    }

    public function test_another_companys_account_id_is_rejected_with_a_422_not_a_500(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherAccount = ChartOfAccount::create(['code' => '2100', 'name' => 'AP', 'type' => AccountType::Liability]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/supplier-accounts', $this->payload(['payable_account_id' => $otherAccount->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');
    }

    public function test_a_supplier_account_is_soft_deleted(): void
    {
        $user = User::factory()->create();
        $account = SupplierAccount::create($this->payload());

        $this->actingAs($user)->deleteJson("/api/v1/supplier-accounts/{$account->id}")->assertNoContent();

        $this->assertSoftDeleted('supplier_accounts', ['id' => $account->id]);
    }

    public function test_another_companys_supplier_account_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherParty = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $otherAccount = ChartOfAccount::create(['code' => '2100', 'name' => 'AP', 'type' => AccountType::Liability]);
        $otherSupplierAccount = SupplierAccount::create([
            'party_id' => $otherParty->id, 'code' => 'ACME', 'payment_terms_days' => 30,
            'payable_account_id' => $otherAccount->id,
        ]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/supplier-accounts/{$otherSupplierAccount->id}")->assertNotFound();
    }
}
