<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Parties\Actions\CreateCustomerAccount;
use App\Modules\Parties\Actions\CreateParty;
use App\Modules\Parties\Actions\CreatePartyContact;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyKind;
use App\Support\CompanyContext;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo data for the Trading partners and Customers screens, entered through the real Actions by the demo login (which StockDemoSeeder has already given
 * every capability) so the same guards, defaults and validation apply as in the product.
 *
 * Create-only and idempotent: a partner, contact or account that already exists (by code / by name within the partner) is left exactly as it is, so a rerun
 * adds nothing and a hand-edited record survives. It reads the company from the current `CompanyContext` and never touches company settings.
 *
 * What it is shaped to show:
 *  - contacts on the two existing demo customers (one primary each, a second contact, one with only a phone);
 *  - a partner that is **both** a customer and a supplier (the existing Punjab Pigments), to show independent roles;
 *  - an **archived** customer account and a customer with no credit limit, for the status filter and the "No limit" state;
 *  - an individual contracting buyer with **no role yet**, for the "No role yet" badge and the "Make a customer" shortcut.
 */
final class PartnersAndContactsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    /** @var array<string, list<array{name: string, title: ?string, email: ?string, phone: ?string, primary: bool}>> */
    private const CONTACTS = [
        'cus-alpha' => [
            ['name' => 'Imran Siddiqui', 'title' => 'Purchasing manager', 'email' => 'imran.siddiqui@alpha-decorators.example', 'phone' => '+92 300 5550101', 'primary' => true],
            ['name' => 'Ayesha Farooq', 'title' => 'Accounts payable', 'email' => 'ayesha.farooq@alpha-decorators.example', 'phone' => null, 'primary' => false],
        ],
        'cus-metro' => [
            ['name' => 'Bilal Ahmed', 'title' => 'Site engineer', 'email' => 'bilal.ahmed@metro-engineering.example', 'phone' => '+92 321 5550142', 'primary' => true],
            ['name' => 'Metro front desk', 'title' => null, 'email' => null, 'phone' => '+92 42 5550177', 'primary' => false],
        ],
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $company = app(CompanyContext::class)->id();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        $receivables = ChartOfAccount::query()->where('code', '1200')->firstOrFail();

        $createParty = app(CreateParty::class);
        $createContact = app(CreatePartyContact::class);
        $createCustomer = app(CreateCustomerAccount::class);

        // Contacts on partners that PartiesDemoSeeder already registered.
        foreach (self::CONTACTS as $partyCode => $contacts) {
            $party = Party::query()->where('code', $partyCode)->first();
            if ($party === null) {
                continue;
            }
            foreach ($contacts as $contact) {
                if ($party->contacts()->where('name', $contact['name'])->exists()) {
                    continue;
                }
                $createContact($party, [
                    'name' => $contact['name'], 'title' => $contact['title'], 'email' => $contact['email'],
                    'phone' => $contact['phone'], 'is_primary' => $contact['primary'],
                ], $actor);
            }
        }

        // One partner that is both a supplier (PartiesDemoSeeder) and a customer: the roles are independent accounts on one identity.
        $this->customerFor('sup-punjab', 'cus-punjab', ['terms' => 30, 'limit' => '250000.0000', 'status' => Status::Active], $actor, $createCustomer, $pkr, $receivables);

        // An archived customer and one with no credit limit.
        $lahore = $this->party('cus-lahore-paints', 'Lahore Paint House', PartyKind::Organisation, ['tax_id' => 'NTN-4417201-3'], $actor, $createParty);
        $this->account($lahore, 'cus-lahore-paints', ['terms' => 15, 'limit' => '120000.0000', 'status' => Status::Archived], $actor, $createCustomer, $pkr, $receivables);
        $karachi = $this->party('cus-karachi-coatings', 'Karachi Coatings Traders', PartyKind::Organisation, ['registration_no' => 'KHI-2291'], $actor, $createParty);
        $this->account($karachi, 'cus-karachi-coatings', ['terms' => 60, 'limit' => null, 'status' => Status::Active], $actor, $createCustomer, $pkr, $receivables);

        // An individual contracting buyer with no financial role yet.
        $this->party('ind-zainab-khan', 'Zainab Khan', PartyKind::Individual, [], $actor, $createParty);
    }

    /** @param array<string, mixed> $extra */
    private function party(string $code, string $name, PartyKind $kind, array $extra, User $actor, CreateParty $create): Party
    {
        $existing = Party::withTrashed()->where('code', $code)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $create(['code' => $code, 'name' => $name, 'kind' => $kind->value, 'custom_fields' => []] + $extra, $actor);
    }

    /** @param array{terms: int, limit: ?string, status: Status} $terms */
    private function account(Party $party, string $code, array $terms, User $actor, CreateCustomerAccount $create, Currency $currency, ChartOfAccount $receivables): void
    {
        if (CustomerAccount::withTrashed()->where('party_id', $party->getKey())->exists() || CustomerAccount::withTrashed()->where('code', $code)->exists()) {
            return;
        }

        $create([
            'party_id' => $party->getKey(),
            'code' => $code,
            'payment_terms_days' => $terms['terms'],
            'receivable_account_id' => $receivables->getKey(),
            'default_currency_id' => $currency->getKey(),
            'custom_fields' => [],
            'status' => $terms['status']->value,
        ] + ($terms['limit'] !== null ? ['credit_limit' => $terms['limit']] : []), $actor);
    }

    /** @param array{terms: int, limit: ?string, status: Status} $terms */
    private function customerFor(string $partyCode, string $accountCode, array $terms, User $actor, CreateCustomerAccount $create, Currency $currency, ChartOfAccount $receivables): void
    {
        $party = Party::query()->where('code', $partyCode)->first();
        if ($party !== null) {
            $this->account($party, $accountCode, $terms, $actor, $create, $currency, $receivables);
        }
    }
}
