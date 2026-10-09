<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Support\PartyCandidateAccess;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** ADR 0105: organisation and individual identities, duplicate candidates that never merge, and governed restore of an archived identity. */
class PartyIdentityReuseTest extends TestCase
{
    use RefreshDatabase;

    private function maintainer(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('parties.party.manage');
        Permission::findOrCreate('sales.test-search');
        $user = User::factory()->create();
        $user->givePermissionTo('parties.party.manage');

        return $user;
    }

    public function test_every_existing_party_is_an_organisation_and_an_individual_can_be_created(): void
    {
        $existing = Party::create(['code' => 'ABC', 'name' => 'ABC Industries']);
        $this->assertSame('ORGANISATION', $existing->refresh()->kind->value);

        $this->actingAs($this->maintainer());
        $this->postJson('/api/v1/parties', ['code' => 'FATIMA', 'name' => 'Fatima Khan', 'kind' => 'INDIVIDUAL'])->assertCreated()->assertJsonPath('data.kind', 'INDIVIDUAL');
        $this->postJson('/api/v1/parties', ['code' => 'BAD', 'name' => 'Bad', 'kind' => 'ROBOT'])->assertUnprocessable();
        $this->postJson('/api/v1/parties', ['code' => 'DEF', 'name' => 'Default Co'])->assertCreated()->assertJsonPath('data.kind', 'ORGANISATION');
        $this->assertSame(0, DB::table('customer_accounts')->count(), 'identity creation never implies a financial role');
    }

    public function test_the_database_refuses_an_unknown_kind(): void
    {
        $this->expectException(QueryException::class);
        DB::table('parties')->insert(['company_id' => 1, 'code' => 'X', 'name' => 'X', 'kind' => 'ROBOT', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_candidates_are_found_by_name_tax_id_registration_code_email_and_phone_but_only_found(): void
    {
        $abc = Party::create(['code' => 'ABC', 'name' => 'ABC Industries (Pvt) Ltd.', 'tax_id' => 'NTN 123-4567', 'registration_no' => 'REG/99']);
        PartyContact::create(['party_id' => $abc->id, 'name' => 'Fatima', 'email' => 'Fatima@ABC.example', 'phone' => '+92 300 111 2222']);
        Party::create(['code' => 'OTHER', 'name' => 'Unrelated Trading']);
        $before = Party::query()->count();

        $this->actingAs($this->maintainer());
        $byName = $this->getJson('/api/v1/parties/candidates?name='.urlencode('abc  industries pvt ltd'))->assertOk()->json('data');
        $this->assertSame(['name'], $byName[0]['matched_on']);
        $this->assertSame($abc->id, $byName[0]['id']);
        $this->assertSame(1, count($byName));

        $all = $this->getJson('/api/v1/parties/candidates?'.http_build_query(['tax_id' => 'ntn1234567', 'registration_no' => 'reg 99', 'code' => 'abc', 'email' => 'fatima@abc.example', 'phone' => '0092-300-1112222']))->assertOk()->json('data');
        $this->assertSame(1, count($all));
        $this->assertEqualsCanonicalizing(['tax_id', 'registration_no', 'code', 'email'], $all[0]['matched_on']);

        $this->assertSame([], $this->getJson('/api/v1/parties/candidates?name=Industries')->json('data'), 'a similar name alone is not the same identity');
        $this->assertSame([], $this->getJson('/api/v1/parties/candidates')->json('data'));
        $this->assertSame($before, Party::query()->count(), 'searching creates, merges and changes nothing');
    }

    public function test_archived_candidates_are_visible_only_to_someone_who_may_restore_them(): void
    {
        $archived = Party::create(['code' => 'OLD', 'name' => 'Old Customer']);
        $archived->delete();

        $this->actingAs($this->maintainer());
        $found = $this->getJson('/api/v1/parties/candidates?name=Old+Customer')->assertOk()->json('data');
        $this->assertSame('ARCHIVED', $found[0]['status']);

        // Someone who may choose a party but not restore one sees active identities only.
        PartyCandidateAccess::allow('sales.test-search');
        $searcher = User::factory()->create();
        $searcher->givePermissionTo('sales.test-search');
        Party::create(['code' => 'LIVE', 'name' => 'Old Customer']);
        $this->actingAs($searcher);
        $visible = $this->getJson('/api/v1/parties/candidates?name=Old+Customer')->assertOk()->json('data');
        $this->assertSame(['ACTIVE'], array_column($visible, 'status'));
        $this->assertSame(1, count($visible));
    }

    public function test_candidate_search_needs_a_duty_that_chooses_parties(): void
    {
        $this->maintainer();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/parties/candidates?name=x')->assertForbidden();
    }

    public function test_restoring_returns_the_identity_but_not_its_financial_roles(): void
    {
        $party = Party::create(['code' => 'OLD', 'name' => 'Old Customer']);
        $receivable = ChartOfAccount::create(['code' => '1200', 'name' => 'Trade Receivables', 'type' => AccountType::Asset]);
        CustomerAccount::create(['party_id' => $party->id, 'code' => 'CUST-OLD', 'payment_terms_days' => 30, 'receivable_account_id' => $receivable->id]);
        DB::table('customer_accounts')->where('party_id', $party->id)->update(['deleted_at' => now()]);
        $party->delete();

        $this->actingAs($this->maintainer());
        $this->postJson("/api/v1/parties/{$party->id}/restore")->assertOk()->assertJsonPath('data.code', 'OLD');
        $this->assertNull(DB::table('parties')->where('id', $party->id)->value('deleted_at'));
        $this->assertNotNull(DB::table('customer_accounts')->where('party_id', $party->id)->value('deleted_at'), 'the customer role stays archived');
        $this->postJson("/api/v1/parties/{$party->id}/restore")->assertOk(); // idempotent
    }

    public function test_restore_is_refused_without_the_capability(): void
    {
        $this->maintainer();
        $party = Party::create(['code' => 'OLD', 'name' => 'Old']);
        $party->delete();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/parties/{$party->id}/restore")->assertForbidden();

        $this->actingAs($this->maintainer())->postJson("/api/v1/parties/{$party->id}/restore")->assertOk();
    }

    public function test_another_companys_party_is_unreachable_by_id(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $foreign = Party::create(['code' => 'FOREIGN', 'name' => 'Foreign Party']);
        $this->app->instance(CompanyContext::class, new CompanyContext);
        $foreign->delete();

        $this->actingAs($this->maintainer());
        $this->postJson("/api/v1/parties/{$foreign->id}/restore")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/parties/candidates?name=Foreign+Party')->json('data'));
    }
}
