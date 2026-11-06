<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function maintainer(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('parties.customer-account.manage');
        Permission::findOrCreate('accounting.account-mapping.manage');
        Permission::findOrCreate('parties.payment-terms.manage');
        Permission::findOrCreate('parties.credit-limit.manage');
        $user = User::factory()->create();
        $user->givePermissionTo('parties.customer-account.manage');
        $user->givePermissionTo('accounting.account-mapping.manage');
        $user->givePermissionTo('parties.payment-terms.manage');
        $user->givePermissionTo('parties.credit-limit.manage');

        return $user;
    }

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

    public function test_shared_customer_reads_do_not_grant_customer_writes(): void
    {
        $account = CustomerAccount::create($this->payload());
        $this->actingAs(User::factory()->create())->getJson('/api/v1/customer-accounts')->assertOk();
        $this->getJson("/api/v1/customer-accounts/{$account->id}")->assertOk();
        $this->postJson('/api/v1/customer-accounts', $this->payload(['code' => 'OTHER']))->assertForbidden();
        $this->patchJson("/api/v1/customer-accounts/{$account->id}", ['credit_limit' => '999'])->assertForbidden();
        $this->deleteJson("/api/v1/customer-accounts/{$account->id}")->assertForbidden();
        $this->assertDatabaseHas('customer_accounts', ['id' => $account->id, 'credit_limit' => null, 'deleted_at' => null]);
    }

    public function test_a_customer_account_can_be_created_and_fetched(): void
    {
        $user = $this->maintainer();

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
        $user = $this->maintainer();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload(['party_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
    }

    public function test_a_credit_limit_exceeding_the_columns_precision_is_rejected_with_a_422_not_a_500(): void
    {
        $user = $this->maintainer();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'credit_limit' => '1234567890123456',
        ]))->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    }

    public function test_payment_terms_days_outside_the_smallint_range_is_rejected_with_a_422_not_a_500(): void
    {
        $user = $this->maintainer();

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'payment_terms_days' => 99999,
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_terms_days');

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload([
            'payment_terms_days' => -1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_terms_days');
    }

    public function test_tax_code_is_rejected_until_the_tax_codes_table_exists(): void
    {
        $user = $this->maintainer();

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
        $user = $this->maintainer();
        CustomerAccount::create($this->payload());

        $this->actingAs($user)->postJson('/api/v1/customer-accounts', $this->payload(['code' => 'ACME-2']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
    }

    public function test_show_can_include_the_party(): void
    {
        $user = $this->maintainer();
        $account = CustomerAccount::create($this->payload());

        $response = $this->actingAs($user)->getJson("/api/v1/customer-accounts/{$account->id}?include=party");

        $response->assertOk();
        $response->assertJsonPath('data.party.code', 'ACME');
    }

    public function test_a_customer_account_is_soft_deleted(): void
    {
        $user = $this->maintainer();
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

        $user = $this->maintainer();

        $this->actingAs($user)->getJson("/api/v1/customer-accounts/{$otherCustomerAccount->id}")->assertNotFound();
    }

    public function test_index_can_search_by_code_party_name_tax_id_and_registration(): void
    {
        $this->party->update(['tax_id' => 'TAX-778', 'registration_no' => 'REG-5521']);
        CustomerAccount::create($this->payload(['code' => 'CUS-001']));
        $user = User::factory()->create();

        foreach (['CUS-001', 'Acme', 'TAX-778', 'REG-5521'] as $term) {
            $res = $this->actingAs($user)->getJson('/api/v1/customer-accounts?filter[search]='.$term)->assertOk();
            $this->assertCount(1, $res->json('data'), "search '{$term}' should find the account");
        }

        $this->assertCount(0, $this->actingAs($user)->getJson('/api/v1/customer-accounts?filter[search]=NonExistent')->assertOk()->json('data'));
    }

    public function test_index_supports_currency_and_receivable_filters_and_the_extra_sorts(): void
    {
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        $eur = Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€']);
        $partyB = Party::create(['code' => 'BETA', 'name' => 'Beta Ltd']);
        $accountB = ChartOfAccount::create(['code' => '1101', 'name' => 'Other AR', 'type' => AccountType::Asset]);

        CustomerAccount::create($this->payload(['code' => 'CUS-A', 'payment_terms_days' => 10, 'default_currency_id' => $usd->id]));
        CustomerAccount::create([
            'party_id' => $partyB->id, 'code' => 'CUS-B', 'payment_terms_days' => 60,
            'default_currency_id' => $eur->id, 'receivable_account_id' => $accountB->id,
        ]);
        $user = $this->maintainer();

        $byCurrency = $this->actingAs($user)->getJson("/api/v1/customer-accounts?filter[default_currency_id]={$usd->id}")->assertOk();
        $this->assertSame(['CUS-A'], array_column($byCurrency->json('data'), 'code'));

        $byAccount = $this->actingAs($user)->getJson("/api/v1/customer-accounts?filter[receivable_account_id]={$accountB->id}")->assertOk();
        $this->assertSame(['CUS-B'], array_column($byAccount->json('data'), 'code'));

        $this->assertSame(['CUS-A', 'CUS-B'], array_column($this->actingAs($user)->getJson('/api/v1/customer-accounts?sort=payment_terms_days')->json('data'), 'code'));
        $this->assertSame(['CUS-B', 'CUS-A'], array_column($this->actingAs($user)->getJson('/api/v1/customer-accounts?sort=-payment_terms_days')->json('data'), 'code'));
    }

    public function test_the_receivable_account_filter_is_ignored_for_a_reader_without_mapping_access(): void
    {
        $accountB = ChartOfAccount::create(['code' => '1101', 'name' => 'Other AR', 'type' => AccountType::Asset]);
        $partyB = Party::create(['code' => 'BETA', 'name' => 'Beta Ltd']);
        CustomerAccount::create($this->payload(['code' => 'CUS-A']));
        CustomerAccount::create(['party_id' => $partyB->id, 'code' => 'CUS-B', 'payment_terms_days' => 30, 'receivable_account_id' => $accountB->id]);

        // A plain reader must not be able to probe a hidden mapping by filtering on it: the filter is simply not applied.
        $res = $this->actingAs(User::factory()->create())->getJson("/api/v1/customer-accounts?filter[receivable_account_id]={$accountB->id}")->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertArrayNotHasKey('receivable_account_id', $res->json('data.0'));
    }
}
