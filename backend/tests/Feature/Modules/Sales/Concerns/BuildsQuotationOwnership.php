<?php

namespace Tests\Feature\Modules\Sales\Concerns;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixtures for the QA1a ownership tests: people with each independent duty, a legacy (UNRESOLVED) family, a family created the real way, and a second company.
 * The shipped manifest keeps the quotation duties STAGED; `activateOwnershipDuties()` switches the three on only to exercise Actions it keeps refusing.
 */
trait BuildsQuotationOwnership
{
    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private const VIEW = PermissionVocabulary::QUOTATION_VIEW;

    private const ASSIGN = PermissionVocabulary::QUOTATION_ASSIGN;

    private User $preparer;

    private User $assigner;

    private User $colleague;

    private User $leaver;

    private User $viewOnly;

    private User $assignOnly;

    private int $otherCompany;

    private int $otherUser;

    private Party $prospect;

    private string $today;

    private int $keys = 0;

    protected function setUpOwnership(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([self::PREPARE, self::VIEW, self::ASSIGN] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->preparer = User::factory()->create();
        $this->preparer->givePermissionTo([self::PREPARE, self::VIEW]);
        $this->assigner = User::factory()->create();
        $this->assigner->givePermissionTo([self::ASSIGN, self::VIEW]);
        $this->viewOnly = User::factory()->create();
        $this->viewOnly->givePermissionTo([self::VIEW]);
        $this->assignOnly = User::factory()->create();
        $this->assignOnly->givePermissionTo([self::ASSIGN]);
        $this->colleague = User::factory()->create();
        $this->leaver = User::factory()->create();
        $this->leaver->forceFill(['status' => MemberStatus::Suspended])->save();

        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->today = now(Company::findOrFail(1)->timezone)->format('Y-m-d');
        $this->prospect = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function activateOwnershipDuties(): void
    {
        $on = [self::PREPARE, self::VIEW, self::ASSIGN];
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, $on, true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    /** A family created the real way: NATIVE, owned by its creator. */
    private function nativeFamily(?User $by = null): QuotationFamily
    {
        $outcome = app(CreateQuotationDraft::class)($by ?? $this->preparer, $this->key(), ['recipient_party_id' => $this->prospect->id, 'pricing_date' => $this->today]);

        return QuotationFamily::findOrFail($outcome->familyId);
    }

    /** A family that predates ownership: UNRESOLVED, no owner, created by someone who must not become its owner. */
    private function legacyFamily(?User $creator = null): QuotationFamily
    {
        return QuotationFamily::create(['created_by' => ($creator ?? $this->preparer)->id])->refresh();
    }

    private function foreignFamilyId(): int
    {
        return DB::table('quotation_families')->insertGetId(['company_id' => $this->otherCompany, 'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function unit(?string $code = null, string $status = 'ACTIVE', ?int $company = null): int
    {
        $code ??= 'U'.++$this->keys;

        return DB::table('organisation_units')->insertGetId(['company_id' => $company ?? 1, 'code' => $code, 'name' => $code, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
}
