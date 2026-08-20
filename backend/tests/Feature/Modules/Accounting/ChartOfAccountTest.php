<?php

namespace Tests\Feature\Modules\Accounting;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Accounting\Support\NormalBalance;
use App\Support\CompanyContext;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ChartOfAccountTest extends TestCase
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

    public function test_code_is_unique_per_company(): void
    {
        ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->expectException(QueryException::class);

        ChartOfAccount::create(['code' => '1000', 'name' => 'Duplicate', 'type' => AccountType::Asset]);
    }

    public function test_it_is_company_scoped(): void
    {
        ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->assertSame(1, ChartOfAccount::query()->first()->company_id);
    }

    public function test_status_defaults_to_active_and_is_postable_defaults_to_true(): void
    {
        $account = ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->assertSame(Status::Active, $account->fresh()->status);
        $this->assertTrue($account->fresh()->is_postable);
    }

    public function test_normal_balance_follows_from_type(): void
    {
        $this->assertSame(NormalBalance::Debit, AccountType::Asset->normalBalance());
        $this->assertSame(NormalBalance::Debit, AccountType::Expense->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Liability->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Equity->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Income->normalBalance());
    }

    public function test_parent_and_children_relations_resolve(): void
    {
        $parent = ChartOfAccount::create(['code' => '1000', 'name' => 'Assets', 'type' => AccountType::Asset]);
        $child = ChartOfAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => AccountType::Asset, 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertSame($child->id, $parent->children->sole()->id);
    }

    public function test_a_chart_of_account_cannot_be_set_as_its_own_parent(): void
    {
        $a = ChartOfAccount::create(['code' => 'A', 'name' => 'A', 'type' => AccountType::Asset]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $a->update(['parent_id' => $a->id]);
    }

    public function test_reparenting_under_its_own_descendant_is_rejected_on_update(): void
    {
        $a = ChartOfAccount::create(['code' => 'A', 'name' => 'A', 'type' => AccountType::Asset]);
        $b = ChartOfAccount::create(['code' => 'B', 'name' => 'B', 'type' => AccountType::Asset, 'parent_id' => $a->id]);
        $c = ChartOfAccount::create(['code' => 'C', 'name' => 'C', 'type' => AccountType::Asset, 'parent_id' => $b->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $a->update(['parent_id' => $c->id]);
    }

    public function test_parent_id_must_resolve_to_an_account_that_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        ChartOfAccount::create(['code' => 'A', 'name' => 'A', 'type' => AccountType::Asset, 'parent_id' => 999999]);
    }

    public function test_parent_id_cannot_point_at_another_companys_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a ChartOfAccount');

        ChartOfAccount::create([
            'code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'parent_id' => $otherCompanyAccount->id,
        ]);
    }
}
