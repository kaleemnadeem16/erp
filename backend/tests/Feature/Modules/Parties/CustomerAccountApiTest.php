<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    private ChartOfAccount $receivableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $this->receivableAccount = ChartOfAccount::create([
            'code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'party_id' => $this->party->id,
            'code' => 'ACME',
            'payment_terms_days' => 30,
            'receivable_account_id' => $this->receivableAccount->id,
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/customer-accounts')->assertUnauthorized();
    }

    public function test_a_customer_account_can_be_created_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'ACME');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.custom_fields', []);
        $id = $response->json('data.id');

        $this->getJson("/api/v1/customer-accounts/{$id}")->assertOk()->assertJsonPath('data.payment_terms_days', 30);
    }

    public function test_a_nonexistent_party_id_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload(['party_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
    }

    public function test_a_credit_limit_exceeding_the_columns_precision_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'credit_limit' => '1234567890123456',
        ]))->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    }

    public function test_payment_terms_days_outside_the_smallint_range_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'payment_terms_days' => 99999,
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_terms_days');

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'payment_terms_days' => -1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_terms_days');
    }

    public function test_tax_code_is_rejected_until_the_tax_codes_table_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'tax_code_id' => 123,
        ]))->assertUnprocessable()->assertJsonValidationErrors('tax_code_id');

        $account = CustomerAccount::create($this->payload());
        $this->patchJson("/api/v1/customer-accounts/{$account->id}", ['tax_code_id' => 123])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_code_id');
        $this->assertNull($account->refresh()->tax_code_id);
    }

    public function test_a_party_can_only_hold_one_customer_account_duplicate_is_rejected_with_a_422(): void
    {
        $user = User::factory()->create();
        CustomerAccount::create($this->payload());

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload(['code' => 'ACME-2']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
    }

    public function test_show_can_include_the_party(): void
    {
        $user = User::factory()->create();
        $account = CustomerAccount::create($this->payload());

        $response = $this->actingAs($user)->getJson("/api/v1/customer-accounts/{$account->id}?include=party");

        $response->assertOk();
        $response->assertJsonPath('data.party.code', 'ACME');
    }

    public function test_a_customer_account_is_soft_deleted(): void
    {
        $user = User::factory()->create();
        $account = CustomerAccount::create($this->payload());

        $this->actingAs($user)->deleteJson("/api/v1/customer-accounts/{$account->id}")->assertNoContent();

        $this->assertSoftDeleted('customer_accounts', ['id' => $account->id]);
    }

    public function test_another_companys_customer_account_is_not_visible(): void
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
        $otherAccount = ChartOfAccount::create(['code' => '1100', 'name' => 'AR', 'type' => AccountType::Asset]);
        $otherCustomerAccount = CustomerAccount::create([
            'party_id' => $otherParty->id, 'code' => 'ACME', 'payment_terms_days' => 30,
            'receivable_account_id' => $otherAccount->id,
        ]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/customer-accounts/{$otherCustomerAccount->id}")->assertNotFound();
    }
}
