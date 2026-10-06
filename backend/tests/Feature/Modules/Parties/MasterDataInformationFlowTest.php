<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Shared master-data reads intentionally remain authenticated-only pending Q34; nested data must still be tenant-safe. */
class MasterDataInformationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_lists_details_lookups_and_nested_includes_never_return_foreign_records(): void
    {
        $viewer = User::factory()->create();
        $own = $this->records('OWN');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $original = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $foreign = $this->records('SECRET');
        app()->instance(CompanyContext::class, $original);
        $cases = [
            ['parties', 'customer_account,supplier_account', 'party'],
            ['customer-accounts', 'party', 'customer'],
            ['supplier-accounts', 'party', 'supplier'],
            ['chart-of-accounts', 'parent,children', 'account'],
        ];
        foreach ($cases as [$path, $include, $key]) {
            $this->actingAs($viewer)->getJson("/api/v1/{$path}?include={$include}")->assertOk()->assertJsonMissing(['code' => 'SECRET']);
            $response = $this->getJson("/api/v1/{$path}/{$own[$key]->id}?include={$include}")->assertOk()->assertJsonPath('data.id', $own[$key]->id);
            $this->assertStringNotContainsString('SECRET', $response->getContent());
            $this->getJson("/api/v1/{$path}/{$foreign[$key]->id}?include={$include}")->assertNotFound()->assertJsonMissing(['code' => 'SECRET']);
            $response = $this->getJson("/api/v1/{$path}?filter[search]=SECRET&include={$include}")->assertOk();
            $this->assertStringNotContainsString('SECRET', json_encode($response->json('data')));
        }
        $response = $this->getJson("/api/v1/parties/{$own['party']->id}?include=customer_account,supplier_account")->assertOk()
            ->assertJsonMissingPath('data.customer_account.credit_limit')
            ->assertJsonMissingPath('data.customer_account.receivable_account_id')
            ->assertJsonMissingPath('data.supplier_account.payable_account_id')
            ->assertJsonPath('data.customer_account.payment_terms_days', 30);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->getJson("/api/v1/supplier-accounts/{$own['supplier']->id}/sites")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.party_site.name', 'OWN address');
        $this->getJson("/api/v1/supplier-accounts/{$foreign['supplier']->id}/sites")->assertNotFound();
        $this->getJson('/api/v1/parties?include=customer_account.party')->assertUnprocessable();
        $this->getJson('/api/v1/chart-of-accounts?include=children.parent')->assertUnprocessable();
    }

    /** @return array<string, mixed> */
    private function records(string $code): array
    {
        $account = ChartOfAccount::create(['code' => $code, 'name' => $code.' control account', 'type' => 'ASSET']);
        ChartOfAccount::create(['code' => $code.'-CHILD', 'name' => $code.' child', 'type' => 'ASSET', 'parent_id' => $account->id]);
        $party = Party::create(['code' => $code, 'name' => $code.' party']);
        $customer = CustomerAccount::create(['code' => $code, 'party_id' => $party->id, 'credit_limit' => '12345.0000', 'payment_terms_days' => 30, 'receivable_account_id' => $account->id]);
        $supplier = SupplierAccount::create(['code' => $code, 'party_id' => $party->id, 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $address = PartySite::create(['party_id' => $party->id, 'code' => $code, 'name' => $code.' address', 'address_line1' => 'Road', 'country_code' => 'pk']);
        SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $address->id, 'role' => 'ORDER_FROM']);

        return compact('account', 'party', 'customer', 'supplier');
    }
}
