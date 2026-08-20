<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\CustomerSiteRole;
use App\Modules\Parties\Support\SupplierSiteRole;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_unique_per_company(): void
    {
        Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->expectException(QueryException::class);

        Party::create(['code' => 'ACME', 'name' => 'Duplicate']);
    }

    public function test_status_defaults_to_active_and_casts_to_the_enum(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->assertSame(Status::Active, $party->fresh()->status);
    }

    public function test_custom_fields_cast_to_an_array_via_has_custom_fields(): void
    {
        $party = Party::create([
            'code' => 'ACME',
            'name' => 'Acme Industries',
            'custom_fields' => ['site_supervisor' => 'J. Doe'],
        ]);

        $this->assertSame(['site_supervisor' => 'J. Doe'], $party->fresh()->custom_fields);
    }

    public function test_it_is_company_scoped(): void
    {
        Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->assertSame(1, Party::query()->first()->company_id);
    }

    public function test_contacts_and_sites_relations_resolve(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $party->contacts()->create(['name' => 'Jane Doe']);
        $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $this->assertSame('Jane Doe', $party->contacts->sole()->name);
        $this->assertSame('HQ', $party->sites->sole()->code);
    }

    public function test_a_party_can_hold_independent_customer_and_supplier_accounts(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $receivable = ChartOfAccount::create([
            'code' => '1200', 'name' => 'Receivables', 'type' => AccountType::Asset,
        ]);
        $payable = ChartOfAccount::create([
            'code' => '2100', 'name' => 'Payables', 'type' => AccountType::Liability,
        ]);

        $party->customerAccount()->create([
            'code' => 'CUST-ACME', 'payment_terms_days' => 30,
            'receivable_account_id' => $receivable->id,
        ]);
        $party->supplierAccount()->create([
            'code' => 'SUPP-ACME', 'payment_terms_days' => 45,
            'payable_account_id' => $payable->id,
        ]);

        $this->assertSame(30, $party->fresh()->customerAccount->payment_terms_days);
        $this->assertSame(45, $party->fresh()->supplierAccount->payment_terms_days);
    }

    public function test_one_party_site_can_be_shared_by_customer_and_supplier_roles(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $site = $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);
        $receivable = ChartOfAccount::create([
            'code' => '1200', 'name' => 'Receivables', 'type' => AccountType::Asset,
        ]);
        $payable = ChartOfAccount::create([
            'code' => '2100', 'name' => 'Payables', 'type' => AccountType::Liability,
        ]);
        $customer = $party->customerAccount()->create([
            'code' => 'CUST-ACME', 'payment_terms_days' => 30,
            'receivable_account_id' => $receivable->id,
        ]);
        $supplier = $party->supplierAccount()->create([
            'code' => 'SUPP-ACME', 'payment_terms_days' => 45,
            'payable_account_id' => $payable->id,
        ]);

        $customerSite = $customer->sites()->create([
            'party_site_id' => $site->id, 'role' => CustomerSiteRole::BillTo,
        ]);
        $supplierSite = $supplier->sites()->create([
            'party_site_id' => $site->id, 'role' => SupplierSiteRole::OrderFrom,
        ]);

        $this->assertSame($site->id, $customerSite->party_site_id);
        $this->assertSame($site->id, $supplierSite->party_site_id);
    }
}
