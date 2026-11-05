<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Parties\Actions\CreateParty;
use App\Modules\Parties\Actions\CreatePartyContact;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyKind;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo data for the Trading partners and Customers screens, entered through the real Actions by the demo login (which StockDemoSeeder has already given
 * every capability) so the same guards, defaults and validation apply as in the product.
 *
 * Create-only and idempotent: a partner or contact that already exists (by code / by name within the partner) is left exactly as it is, so a rerun adds
 * nothing and a hand-edited record survives. It reads the company from the current `CompanyContext` and never touches company settings.
 *
 * It deliberately creates **no customer account**: the Sales demo guards that no account exists beyond the two financial customers (a prospect has no
 * financial role, ADR 0103), so archived, no-limit and dual-role customers are created by the screens' own specs instead of by the shared demo.
 *
 * What it is shaped to show:
 *  - contacts on the two existing demo customers (one primary each, a second contact, one with only a phone);
 *  - two organisations and an individual contracting buyer with **no role yet**, for the "No role yet" badge, the "Make a customer" shortcut and the
 *    archive action (a partner with no role can be archived).
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

        $createParty = app(CreateParty::class);
        $createContact = app(CreatePartyContact::class);

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

        // Prospect organisations: partners with no financial role yet.
        $this->party('pro-lahore-paints', 'Lahore Paint House', PartyKind::Organisation, ['tax_id' => 'NTN-4417201-3'], $actor, $createParty);
        $this->party('pro-karachi-coatings', 'Karachi Coatings Traders', PartyKind::Organisation, ['registration_no' => 'KHI-2291'], $actor, $createParty);

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
}
