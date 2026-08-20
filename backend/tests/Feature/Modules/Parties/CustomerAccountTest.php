<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\CompanyContext;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

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

    private function createSecondCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Other Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private ?Party $defaultParty = null;

    private ?ChartOfAccount $defaultReceivableAccount = null;

    private function defaultParty(): Party
    {
        return $this->defaultParty ??= Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
    }

    private function defaultReceivableAccount(): ChartOfAccount
    {
        return $this->defaultReceivableAccount ??= ChartOfAccount::create([
            'code' => '1200', 'name' => 'Trade Receivables', 'type' => AccountType::Asset,
        ]);
    }

    /**
     * A distinct party, for tests that need their own rather than the shared default.
     */
    private function makeParty(array $attributes = []): Party
    {
        return Party::create(array_merge(['code' => 'OTHER', 'name' => 'Other Party'], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CUST-ACME',
            'party_id' => $this->defaultParty()->id,
            'payment_terms_days' => 30,
            'receivable_account_id' => $this->defaultReceivableAccount()->id,
        ], $overrides);
    }

    public function test_it_is_company_scoped(): void
    {
        CustomerAccount::create($this->baseAttributes());

        $this->assertSame(1, CustomerAccount::query()->first()->company_id);
    }

    public function test_code_is_unique_per_company(): void
    {
        CustomerAccount::create($this->baseAttributes(['code' => 'CUST-ACME']));

        $this->expectException(QueryException::class);

        CustomerAccount::create($this->baseAttributes(['code' => 'CUST-ACME']));
    }

    public function test_only_one_customer_account_per_party(): void
    {
        $party = $this->makeParty();
        CustomerAccount::create($this->baseAttributes(['party_id' => $party->id, 'code' => 'A']));

        $this->expectException(QueryException::class);

        CustomerAccount::create($this->baseAttributes(['party_id' => $party->id, 'code' => 'B']));
    }

    public function test_status_defaults_to_active(): void
    {
        $account = CustomerAccount::create($this->baseAttributes());

        $this->assertSame(Status::Active, $account->fresh()->status);
    }

    public function test_custom_fields_cast_to_an_array(): void
    {
        $account = CustomerAccount::create($this->baseAttributes(['custom_fields' => ['segment' => 'Retail']]));

        $this->assertSame(['segment' => 'Retail'], $account->fresh()->custom_fields);
    }

    public function test_party_and_receivable_account_relations_resolve(): void
    {
        $party = $this->makeParty();
        $receivableAccount = ChartOfAccount::create(['code' => '1300', 'name' => 'Other AR', 'type' => AccountType::Asset]);
        $account = CustomerAccount::create($this->baseAttributes([
            'party_id' => $party->id, 'receivable_account_id' => $receivableAccount->id,
        ]));

        $this->assertSame($party->id, $account->party->id);
        $this->assertSame($receivableAccount->id, $account->receivableAccount->id);
    }

    public function test_party_id_must_resolve_to_a_party_that_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a Party');

        CustomerAccount::create($this->baseAttributes(['party_id' => 999999]));
    }

    public function test_party_id_cannot_point_at_another_companys_party(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyParty = $this->makeParty();

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a Party');

        CustomerAccount::create($this->baseAttributes(['party_id' => $otherCompanyParty->id]));
    }

    public function test_receivable_account_id_cannot_point_at_another_companys_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '1300', 'name' => 'Other AR', 'type' => AccountType::Asset]);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        CustomerAccount::create($this->baseAttributes(['receivable_account_id' => $otherCompanyAccount->id]));
    }

    public function test_receivable_account_id_cannot_be_updated_to_another_companys_account(): void
    {
        $account = CustomerAccount::create($this->baseAttributes());

        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '1300', 'name' => 'Other AR', 'type' => AccountType::Asset]);
        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        $account->update(['receivable_account_id' => $otherCompanyAccount->id]);
    }
}
