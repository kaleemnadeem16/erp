<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\CustomerSite;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\CustomerSiteRole;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CustomerSiteTest extends TestCase
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

    private function customerAccount(): CustomerAccount
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $receivableAccount = ChartOfAccount::create(['code' => '1200', 'name' => 'Trade Receivables', 'type' => AccountType::Asset]);

        return CustomerAccount::create([
            'code' => 'CUST-ACME', 'party_id' => $party->id,
            'payment_terms_days' => 30, 'receivable_account_id' => $receivableAccount->id,
        ]);
    }

    public function test_role_and_is_default_cast(): void
    {
        $customerAccount = $this->customerAccount();
        $partySite = $customerAccount->party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $site = $customerAccount->sites()->create([
            'party_site_id' => $partySite->id, 'role' => CustomerSiteRole::BillTo, 'is_default' => true,
        ]);

        $this->assertSame(CustomerSiteRole::BillTo, $site->fresh()->role);
        $this->assertTrue($site->fresh()->is_default);
    }

    public function test_the_same_site_can_hold_two_different_roles(): void
    {
        $customerAccount = $this->customerAccount();
        $partySite = $customerAccount->party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $customerAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => CustomerSiteRole::BillTo]);
        $shipTo = $customerAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => CustomerSiteRole::ShipTo]);

        $this->assertCount(2, $customerAccount->sites);
        $this->assertSame(CustomerSiteRole::ShipTo, $shipTo->role);
    }

    public function test_the_same_site_and_role_cannot_be_attached_twice(): void
    {
        $customerAccount = $this->customerAccount();
        $partySite = $customerAccount->party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);
        $customerAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => CustomerSiteRole::BillTo]);

        $this->expectException(QueryException::class);

        $customerAccount->sites()->create(['party_site_id' => $partySite->id, 'role' => CustomerSiteRole::BillTo]);
    }

    public function test_it_cannot_be_created_against_another_companys_customer_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyCustomerAccount = $this->customerAccount();
        $partySite = $otherCompanyCustomerAccount->party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        CustomerSite::create([
            'customer_account_id' => $otherCompanyCustomerAccount->id,
            'party_site_id' => $partySite->id,
            'role' => CustomerSiteRole::BillTo,
        ]);
    }

    public function test_party_site_must_belong_to_the_customer_accounts_party(): void
    {
        $customerAccount = $this->customerAccount();
        $otherParty = Party::create(['code' => 'OTHER', 'name' => 'Other Party']);
        $otherSite = $otherParty->sites()->create(['code' => 'HQ', 'name' => 'Other HQ']);

        $this->expectException(RuntimeException::class);

        $customerAccount->sites()->create([
            'party_site_id' => $otherSite->id,
            'role' => CustomerSiteRole::BillTo,
        ]);
    }

    public function test_relationship_ownership_is_rechecked_on_update(): void
    {
        $customerAccount = $this->customerAccount();
        $ownSite = $customerAccount->party->sites()->create(['code' => 'HQ', 'name' => 'HQ']);
        $link = $customerAccount->sites()->create([
            'party_site_id' => $ownSite->id, 'role' => CustomerSiteRole::BillTo,
        ]);
        $otherParty = Party::create(['code' => 'OTHER', 'name' => 'Other Party']);
        $otherSite = $otherParty->sites()->create(['code' => 'HQ', 'name' => 'Other HQ']);

        $this->expectException(RuntimeException::class);

        $link->update(['party_site_id' => $otherSite->id]);
    }
}
