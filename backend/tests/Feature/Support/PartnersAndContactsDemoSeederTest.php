<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Support\Status;
use Database\Seeders\Demo\PartnersAndContactsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnersAndContactsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_registers_partners_contacts_and_customers_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        // Contacts: one primary each on the two existing demo customers.
        foreach (['cus-alpha', 'cus-metro'] as $code) {
            $party = Party::query()->where('code', $code)->firstOrFail();
            $this->assertGreaterThanOrEqual(2, $party->contacts()->count());
            $this->assertSame(1, $party->contacts()->where('is_primary', true)->count());
        }

        // One partner that holds both roles, independently.
        $both = Party::query()->where('code', 'sup-punjab')->firstOrFail();
        $this->assertNotNull($both->supplierAccount);
        $this->assertSame('cus-punjab', $both->customerAccount?->code);

        // An archived customer, a customer with no limit, and a partner with no role yet.
        $this->assertSame(Status::Archived, CustomerAccount::query()->where('code', 'cus-lahore-paints')->firstOrFail()->status);
        $this->assertNull(CustomerAccount::query()->where('code', 'cus-karachi-coatings')->firstOrFail()->credit_limit);
        $individual = Party::query()->where('code', 'ind-zainab-khan')->firstOrFail();
        $this->assertSame('INDIVIDUAL', $individual->kind->value);
        $this->assertNull($individual->customerAccount);
        $this->assertNull($individual->supplierAccount);

        $parties = Party::withTrashed()->count();
        $accounts = CustomerAccount::withTrashed()->count();
        $contacts = PartyContact::query()->count();

        $this->seed(PartnersAndContactsDemoSeeder::class);

        $this->assertSame($parties, Party::withTrashed()->count());
        $this->assertSame($accounts, CustomerAccount::withTrashed()->count());
        $this->assertSame($contacts, PartyContact::query()->count());
    }

    public function test_a_rerun_leaves_a_hand_edited_record_alone(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        Party::query()->where('code', 'cus-karachi-coatings')->update(['name' => 'Renamed by hand']);
        CustomerAccount::query()->where('code', 'cus-karachi-coatings')->update(['payment_terms_days' => 7]);

        $this->seed(PartnersAndContactsDemoSeeder::class);

        $this->assertSame('Renamed by hand', Party::query()->where('code', 'cus-karachi-coatings')->firstOrFail()->name);
        $this->assertSame(7, CustomerAccount::query()->where('code', 'cus-karachi-coatings')->firstOrFail()->payment_terms_days);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PartnersAndContactsDemoSeeder::class)->run();
    }
}
