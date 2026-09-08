<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class PartiesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        $payables = ChartOfAccount::query()->where('code', '2100')->firstOrFail();
        $receivables = ChartOfAccount::query()->where('code', '1200')->firstOrFail();

        $suppliers = [
            ['code' => 'sup-punjab', 'name' => 'Punjab Pigments', 'terms' => 30, 'lead' => 5],
            ['code' => 'sup-pakchem', 'name' => 'PakChem Industries', 'terms' => 45, 'lead' => 8],
            ['code' => 'sup-coatings', 'name' => 'Industrial Coatings Ltd', 'terms' => 30, 'lead' => 4],
        ];

        foreach ($suppliers as $data) {
            $party = $this->party($data['code'], $data['name']);
            $account = SupplierAccount::withTrashed()->updateOrCreate(
                ['party_id' => $party->getKey()],
                [
                    'code' => $data['code'],
                    'payment_terms_days' => $data['terms'],
                    'lead_time_days' => $data['lead'],
                    'payable_account_id' => $payables->getKey(),
                    'default_currency_id' => $pkr->getKey(),
                    'custom_fields' => [],
                    'status' => Status::Active,
                ],
            );
            $account->restore();
        }

        $customers = [
            ['code' => 'cus-alpha', 'name' => 'Alpha Decorators', 'limit' => '500000.0000', 'terms' => 30],
            ['code' => 'cus-metro', 'name' => 'Metro Engineering Works', 'limit' => '750000.0000', 'terms' => 45],
        ];

        foreach ($customers as $data) {
            $party = $this->party($data['code'], $data['name']);
            $account = CustomerAccount::withTrashed()->updateOrCreate(
                ['party_id' => $party->getKey()],
                [
                    'code' => $data['code'],
                    'credit_limit' => $data['limit'],
                    'payment_terms_days' => $data['terms'],
                    'tax_code_id' => null,
                    'receivable_account_id' => $receivables->getKey(),
                    'default_currency_id' => $pkr->getKey(),
                    'custom_fields' => [],
                    'status' => Status::Active,
                ],
            );
            $account->restore();
        }
    }

    private function party(string $code, string $name): Party
    {
        $party = Party::withTrashed()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'registration_no' => null,
                'tax_id' => null,
                'website' => null,
                'custom_fields' => [],
                'status' => Status::Active,
            ],
        );
        $party->restore();

        return $party;
    }
}
