<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Parties\Support\SupplierSiteRole;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SupplierSiteTest extends TestCase
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

    private function supplierAccount(): SupplierAccount
    {
        $party = Party::create(['code' => 'GLOBEX', 'name' => 'Globex Corp']);
        $payableAccount = ChartOfAccount::create(['code' => '2100', 'name' => 'Trade Payables', 'type' => AccountType::Liability]);

        return SupplierAccount::create([
            'code' => 'SUPP-GLOBEX', 'party_id' => $party->id,
            'payment_terms_days' => 30, 'payable_account_id' => $payableAccount->id,
        ]);
    }

    public function test_role_and_is_default_cast(): void
    {
        $supplierAccount = $this->supplierAccount();
        $partySite = $supplierAccount->party->sites()->create(['code' => 'FACTORY', 'name' => 'Main Factory']);

        $site = $supplierAccount->sites()->create([
            'party_site_id' => $partySite->id, 'role' => SupplierSiteRole::ShipFrom, 'is_default' => true,
        ]);

        $this->assertSame(SupplierSiteRole::ShipFrom, $site->fresh()->role);
        $this->assertTrue($site->fresh()->is_default);
    }

    public function test_the_same_site_and_role_cannot_be_attached_twice(): void
    {
        $supplierAccount = $this->supplierAccount();
        $partySite = $supplierAccount->party->sites()->create(['code' => 'FACTORY', 'name' => 'Main Factory']);
        $supplierAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => SupplierSiteRole::OrderFrom]);

        $this->expectException(QueryException::class);

        $supplierAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => SupplierSiteRole::OrderFrom]);
    }

    public function test_it_cannot_be_created_against_another_companys_supplier_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanySupplierAccount = $this->supplierAccount();
        $partySite = $otherCompanySupplierAccount->party->sites()->create(['code' => 'FACTORY', 'name' => 'Main Factory']);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        SupplierSite::create([
            'supplier_account_id' => $otherCompanySupplierAccount->id,
            'party_site_id' => $partySite->id,
            'role' => SupplierSiteRole::OrderFrom,
        ]);
    }
}
