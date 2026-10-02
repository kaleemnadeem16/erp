<?php

namespace Tests\Feature\Modules\Company;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Company\Models\CompanyChange;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/** Settings → Company through the API: field-level permissions, validation, the lifecycle locks, the audit trail and tenant isolation (ADR 0081). */
class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return PurchasingActor::make(['company.profile.manage', 'company.settings.manage', 'company.history.view']);
    }

    private function currency(string $code): Currency
    {
        return Currency::firstOrCreate(['code' => $code], ['name' => $code, 'symbol' => $code, 'decimal_places' => 2]);
    }

    public function test_any_signed_in_user_reads_the_company_with_a_form_description(): void
    {
        $this->actingAs(PurchasingActor::make([]))->getJson('/api/v1/company')->assertOk()
            ->assertJsonPath('data.values.timezone', 'UTC')->assertJsonPath('data.timezone_is_default', true)
            ->assertJsonPath('data.fields.0.key', 'name')->assertJsonPath('data.fields.0.can_edit', false);
    }

    public function test_the_profile_and_the_settings_need_their_own_permissions(): void
    {
        $profileOnly = PurchasingActor::make(['company.profile.manage']);
        $this->actingAs($profileOnly)->patchJson('/api/v1/company', ['city' => 'Lahore'])->assertOk();
        $this->patchJson('/api/v1/company', ['timezone' => 'Asia/Karachi', 'reason' => 'x'])->assertForbidden();
        $this->actingAs(PurchasingActor::make([]))->patchJson('/api/v1/company', ['city' => 'Karachi'])->assertForbidden();
    }

    public function test_a_profile_change_is_recorded_without_needing_a_reason(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->patchJson('/api/v1/company', ['legal_name' => 'Acme Chemicals (Pvt) Ltd', 'city' => 'Lahore', 'website' => 'https://acme.example'])->assertOk();

        $changes = CompanyChange::query()->orderBy('section')->get();
        $this->assertSame(['CONTACT', 'GENERAL'], $changes->pluck('section')->all());
        $this->assertSame(['city' => null, 'website' => null], $changes[0]->old_values);
        $this->assertSame($user->id, $changes[0]->changed_by);
        $this->assertNull($changes[0]->reason);
    }

    public function test_an_unchanged_save_writes_no_history(): void
    {
        $this->actingAs($this->admin())->patchJson('/api/v1/company', ['city' => 'Lahore'])->assertOk();
        $this->patchJson('/api/v1/company', ['city' => 'Lahore'])->assertOk();

        $this->assertSame(1, CompanyChange::query()->count());
    }

    public function test_governed_fields_need_a_reason_and_the_time_zone_is_validated(): void
    {
        $this->actingAs($this->admin());
        $this->patchJson('/api/v1/company', ['timezone' => 'Asia/Karachi'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/company', ['timezone' => 'Not/A_Zone', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->patchJson('/api/v1/company', ['timezone' => 'Asia/Karachi', 'reason' => 'Head office is in Karachi'])->assertOk()
            ->assertJsonPath('data.values.timezone', 'Asia/Karachi')->assertJsonPath('data.timezone_is_default', false);

        $change = CompanyChange::query()->sole();
        $this->assertSame('LOCALIZATION', $change->section);
        $this->assertSame(['timezone' => 'UTC'], $change->old_values);
        $this->assertSame('Head office is in Karachi', $change->reason);
    }

    public function test_the_short_code_is_normalised_validated_and_unique_across_companies(): void
    {
        $this->actingAs($this->admin());
        $this->patchJson('/api/v1/company', ['code' => 'a', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/company', ['code' => 'ac me', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/company', ['code' => 'acme', 'reason' => 'Initial code'])->assertOk()->assertJsonPath('data.values.code', 'ACME');

        $other = Company::query()->withoutGlobalScopes()->create(['name' => 'Other', 'base_currency_id' => null, 'code' => 'TAKEN']);
        $this->patchJson('/api/v1/company', ['code' => 'TAKEN', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/company', ['code' => 'ACME', 'reason' => 'same'])->assertOk(); // its own code is not a clash
        $this->assertSame('Other', $other->fresh()->name);
    }

    public function test_it_never_changes_another_companys_settings(): void
    {
        $other = Company::query()->withoutGlobalScopes()->create(['name' => 'Other']);

        $this->actingAs($this->admin())->patchJson('/api/v1/company', ['name' => 'Renamed', 'id' => $other->id, 'company_id' => $other->id])->assertOk();

        $this->assertSame('Renamed', Company::query()->findOrFail(1)->name);
        $this->assertSame('Other', $other->fresh()->name);
    }

    public function test_the_base_currency_is_editable_until_financial_activity_exists(): void
    {
        $usd = $this->currency('USD');
        $pkr = $this->currency('PKR');
        $this->actingAs($this->admin());

        $this->patchJson('/api/v1/company', ['base_currency_id' => $usd->id, 'reason' => 'Initial setup'])->assertOk();
        $this->patchJson('/api/v1/company', ['base_currency_id' => $pkr->id, 'reason' => 'We report in rupees'])->assertOk()
            ->assertJsonPath('data.base_currency.code', 'PKR');

        app(FinancialActivity::class)->register(new ExistsProbe('test invoices', fn (): bool => true));

        $this->getJson('/api/v1/company')->assertJsonPath('data.fields.4.locked', true)
            ->assertJsonPath('data.fields.4.locked_reason', fn ($reason) => str_contains($reason, 'test invoices'));
        $this->patchJson('/api/v1/company', ['base_currency_id' => $usd->id, 'reason' => 'Too late'])
            ->assertUnprocessable()->assertJsonValidationErrors('base_currency_id');
        $this->assertSame($pkr->id, Company::query()->findOrFail(1)->base_currency_id);
    }

    public function test_the_lock_holds_for_any_caller_not_only_the_api(): void
    {
        $usd = $this->currency('USD');
        $pkr = $this->currency('PKR');
        DB::table('companies')->where('id', 1)->update(['base_currency_id' => $usd->id]);
        app(FinancialActivity::class)->register(new ExistsProbe('test invoices', fn (): bool => true));

        $this->expectException(DomainInvariantException::class);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $pkr->id]);
    }

    public function test_the_fiscal_year_start_month_locks_once_a_period_is_closed(): void
    {
        $this->actingAs($this->admin());
        $this->patchJson('/api/v1/company', ['fiscal_year_start_month' => 7, 'reason' => 'Fiscal year starts in July'])->assertOk();

        $year = FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31']);
        $this->patchJson('/api/v1/company', ['fiscal_year_start_month' => 1, 'reason' => 'Open periods only'])->assertOk();

        AccountingPeriod::query()->update(['status' => PeriodStatus::Closed->value]);
        $this->getJson('/api/v1/company')->assertJsonPath('data.fields.5.locked', true);
        $this->patchJson('/api/v1/company', ['fiscal_year_start_month' => 4, 'reason' => 'Too late'])
            ->assertUnprocessable()->assertJsonValidationErrors('fiscal_year_start_month');
    }

    public function test_the_history_needs_its_own_permission_and_lists_newest_first(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patchJson('/api/v1/company', ['city' => 'Lahore'])->assertOk();
        $this->patchJson('/api/v1/company', ['city' => 'Karachi'])->assertOk();

        $this->getJson('/api/v1/company-changes')->assertOk()->assertJsonPath('data.0.new_values.city', 'Karachi')->assertJsonCount(2, 'data');
        $this->actingAs(PurchasingActor::make(['company.profile.manage']))->getJson('/api/v1/company-changes')->assertForbidden();
    }

    public function test_history_rows_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->admin())->patchJson('/api/v1/company', ['city' => 'Lahore'])->assertOk();
        $change = CompanyChange::query()->sole();

        $this->expectException(QueryException::class);
        DB::table('company_changes')->where('id', $change->id)->update(['reason' => 'tampered']);
    }
}
