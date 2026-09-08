<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class AccountingDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $accounts = [
            ['code' => '1100', 'name' => 'Inventory', 'type' => AccountType::Asset],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => AccountType::Liability],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => AccountType::Income],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => AccountType::Expense],
            ['code' => '5100', 'name' => 'Raw Material Expense', 'type' => AccountType::Expense],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::query()->updateOrCreate(
                ['code' => $account['code']],
                [...$account, 'parent_id' => null, 'is_postable' => true, 'currency_id' => null, 'status' => Status::Active],
            );
        }
    }
}
