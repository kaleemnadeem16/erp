<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use Database\Seeders\Demo\PartnersAndContactsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnersAndContactsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_registers_partners_and_contacts_creates_no_customer_account_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        // Contacts: one primary each on the two existing demo customers.
        foreach (['cus-alpha', 'cus-metro'] as $code) {
            $party = Party::query()->where('code', $code)->firstOrFail();
            $this->assertGreaterThanOrEqual(2, $party->contacts()->count());
            $this->assertSame(1, $party->contacts()->where('is_primary', true)->count());
        }

        // Partners with no financial role yet, one of them an individual.
        foreach (['pro-lahore-paints', 'pro-karachi-coatings', 'ind-zainab-khan'] as $code) {
            $party = Party::query()->where('code', $code)->firstOrFail();
            $this->assertNull($party->customerAccount, "{$code} has no customer role");
            $this->assertNull($party->supplierAccount, "{$code} has no supplier role");
        }
        $this->assertSame('INDIVIDUAL', Party::query()->where('code', 'ind-zainab-khan')->firstOrFail()->kind->value);

        // The shared demo adds no customer account: a prospect has no financial role (SalesCrmDemoSeederTest guards the same).
        $this->assertSame(0, CustomerAccount::query()->whereNotIn('code', ['cus-alpha', 'cus-metro'])->count());

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
        Party::query()->where('code', 'pro-karachi-coatings')->update(['name' => 'Renamed by hand']);
        $contact = PartyContact::query()->where('name', 'Imran Siddiqui')->firstOrFail();
        $contact->update(['title' => 'Edited by hand']);

        $this->seed(PartnersAndContactsDemoSeeder::class);

        $this->assertSame('Renamed by hand', Party::query()->where('code', 'pro-karachi-coatings')->firstOrFail()->name);
        $this->assertSame('Edited by hand', $contact->fresh()->title);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PartnersAndContactsDemoSeeder::class)->run();
    }
}
