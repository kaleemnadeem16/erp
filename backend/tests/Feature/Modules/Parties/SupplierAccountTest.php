<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\CompanyContext;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SupplierAccountTest extends TestCase
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

    private ?ChartOfAccount $defaultPayableAccount = null;

    private function defaultParty(): Party
    {
        return $this->defaultParty ??= Party::create(['code' => 'GLOBEX', 'name' => 'Globex Corp']);
    }

    private function defaultPayableAccount(): ChartOfAccount
    {
        return $this->defaultPayableAccount ??= ChartOfAccount::create([
            'code' => '2100', 'name' => 'Trade Payables', 'type' => AccountType::Liability,
        ]);
    }

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
            'code' => 'SUPP-GLOBEX',
            'party_id' => $this->defaultParty()->id,
            'payment_terms_days' => 30,
            'payable_account_id' => $this->defaultPayableAccount()->id,
        ], $overrides);
    }

    public function test_it_is_company_scoped(): void
    {
        SupplierAccount::create($this->baseAttributes());

        $this->assertSame(1, SupplierAccount::query()->first()->company_id);
    }

    public function test_code_is_unique_per_company(): void
    {
        SupplierAccount::create($this->baseAttributes(['code' => 'SUPP-GLOBEX']));

        $this->expectException(QueryException::class);

        SupplierAccount::create($this->baseAttributes(['code' => 'SUPP-GLOBEX']));
    }

    public function test_only_one_supplier_account_per_party(): void
    {
        $party = $this->makeParty();
        SupplierAccount::create($this->baseAttributes(['party_id' => $party->id, 'code' => 'A']));

        $this->expectException(QueryException::class);

        SupplierAccount::create($this->baseAttributes(['party_id' => $party->id, 'code' => 'B']));
    }

    public function test_status_defaults_to_active(): void
    {
        $account = SupplierAccount::create($this->baseAttributes());

        $this->assertSame(Status::Active, $account->fresh()->status);
    }

    public function test_party_and_payable_account_relations_resolve(): void
    {
        $party = $this->makeParty();
        $payableAccount = ChartOfAccount::create(['code' => '2200', 'name' => 'Other AP', 'type' => AccountType::Liability]);
        $account = SupplierAccount::create($this->baseAttributes([
            'party_id' => $party->id, 'payable_account_id' => $payableAccount->id,
        ]));

        $this->assertSame($party->id, $account->party->id);
        $this->assertSame($payableAccount->id, $account->payableAccount->id);
    }

    public function test_party_id_must_resolve_to_a_party_that_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a Party');

        SupplierAccount::create($this->baseAttributes(['party_id' => 999999]));
    }

    public function test_party_id_cannot_point_at_another_companys_party(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyParty = $this->makeParty();

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a Party');

        SupplierAccount::create($this->baseAttributes(['party_id' => $otherCompanyParty->id]));
    }

    public function test_payable_account_id_cannot_point_at_another_companys_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '2200', 'name' => 'Other AP', 'type' => AccountType::Liability]);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        SupplierAccount::create($this->baseAttributes(['payable_account_id' => $otherCompanyAccount->id]));
    }

    public function test_payable_account_id_cannot_be_updated_to_another_companys_account(): void
    {
        $account = SupplierAccount::create($this->baseAttributes());

        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '2200', 'name' => 'Other AP', 'type' => AccountType::Liability]);
        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        $account->update(['payable_account_id' => $otherCompanyAccount->id]);
    }
}
