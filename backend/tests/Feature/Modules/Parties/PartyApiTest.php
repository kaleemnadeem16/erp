<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\CustomFieldDefinition;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartyApiTest extends TestCase
{
    use RefreshDatabase;

    private function maintainer(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('parties.party.manage');
        $user = User::factory()->create();
        $user->givePermissionTo('parties.party.manage');

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/parties')->assertUnauthorized();
    }

    public function test_shared_party_reads_do_not_grant_party_writes(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/parties')->assertOk();
        $this->getJson("/api/v1/parties/{$party->id}")->assertOk();
        $this->postJson('/api/v1/parties', ['code' => 'OTHER', 'name' => 'Other'])->assertForbidden();
        $this->patchJson("/api/v1/parties/{$party->id}", ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/parties/{$party->id}")->assertForbidden();
        $this->assertDatabaseHas('parties', ['id' => $party->id, 'name' => 'Acme Corp', 'deleted_at' => null]);
    }

    public function test_a_party_can_be_created_and_fetched(): void
    {
        $user = $this->maintainer();

        $response = $this->actingAs($user)->postJson('/api/v1/parties', [
            'code' => 'ACME',
            'name' => 'Acme Corp',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'ACME');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/parties/{$id}")->assertOk()->assertJsonPath('data.name', 'Acme Corp');
    }

    public function test_duplicate_code_is_rejected_with_a_422(): void
    {
        $user = $this->maintainer();
        Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);

        $this->actingAs($user)->postJson('/api/v1/parties', [
            'code' => 'ACME',
            'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_custom_fields_reject_a_value_with_no_matching_definition(): void
    {
        $user = $this->maintainer();

        $this->actingAs($user)->postJson('/api/v1/parties', [
            'code' => 'ACME',
            'name' => 'Acme Corp',
            'custom_fields' => ['not_defined_anywhere' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_fields');
    }

    /**
     * Phase 1 acceptance criterion: a custom field defined for parties appears on
     * create/read with no code change — see the matching Item proof for why this
     * is worth its own test rather than just the rejection case above.
     */
    public function test_a_defined_custom_field_round_trips_through_create_and_read(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => Party::customFieldEntityType(),
            'code' => 'preferred_contact_method',
            'label' => 'Preferred contact method',
            'data_type' => 'string',
        ]);
        $user = $this->maintainer();

        $response = $this->actingAs($user)->postJson('/api/v1/parties', [
            'code' => 'ACME',
            'name' => 'Acme Corp',
            'custom_fields' => ['preferred_contact_method' => 'email'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.custom_fields.preferred_contact_method', 'email');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/parties/{$id}")
            ->assertOk()
            ->assertJsonPath('data.custom_fields.preferred_contact_method', 'email');
    }

    public function test_index_rejects_invalid_filters_and_pagination(): void
    {
        $user = $this->maintainer();

        $this->actingAs($user)
            ->getJson('/api/v1/parties?filter[status]=NOT_A_STATUS')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.status');

        $this->getJson('/api/v1/parties?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_index_can_search_by_name(): void
    {
        $user = $this->maintainer();
        Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        Party::create(['code' => 'GLOBEX', 'name' => 'Globex Inc']);

        $response = $this->actingAs($user)->getJson('/api/v1/parties?filter[search]=acme');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'ACME');
    }

    public function test_a_party_is_soft_deleted(): void
    {
        $user = $this->maintainer();
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);

        $this->actingAs($user)->deleteJson("/api/v1/parties/{$party->id}")->assertNoContent();

        $this->assertSoftDeleted('parties', ['id' => $party->id]);
        $this->getJson("/api/v1/parties/{$party->id}")->assertNotFound();
    }

    public function test_a_party_cannot_be_archived_until_both_roles_are_archived(): void
    {
        $user = $this->maintainer();
        Permission::findOrCreate('parties.customer-account.manage');
        Permission::findOrCreate('parties.supplier-account.manage');
        $user->givePermissionTo('parties.customer-account.manage', 'parties.supplier-account.manage');
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $receivable = ChartOfAccount::create(['code' => '1100', 'name' => 'AR', 'type' => AccountType::Asset]);
        $payable = ChartOfAccount::create(['code' => '2100', 'name' => 'AP', 'type' => AccountType::Liability]);
        $customer = CustomerAccount::create([
            'party_id' => $party->id, 'code' => 'C-ACME', 'payment_terms_days' => 30,
            'receivable_account_id' => $receivable->id,
        ]);
        $supplier = SupplierAccount::create([
            'party_id' => $party->id, 'code' => 'S-ACME', 'payment_terms_days' => 30,
            'lead_time_days' => 5,
            'payable_account_id' => $payable->id,
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/parties/{$party->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party');
        $this->assertNull($party->fresh()->deleted_at);

        $this->deleteJson("/api/v1/customer-accounts/{$customer->id}")->assertNoContent();
        $this->deleteJson("/api/v1/supplier-accounts/{$supplier->id}")->assertNoContent();
        $this->deleteJson("/api/v1/parties/{$party->id}")->assertNoContent();
        $this->assertSoftDeleted('parties', ['id' => $party->id]);
    }

    public function test_another_companys_party_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherParty = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = $this->maintainer();

        $this->actingAs($user)->getJson("/api/v1/parties/{$otherParty->id}")->assertNotFound();
    }
}
