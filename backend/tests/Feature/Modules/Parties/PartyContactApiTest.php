<?php

namespace Tests\Feature\Modules\Parties;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartyContactApiTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->party = Party::create(['code' => 'ACME', 'name' => 'Acme Corp']);
    }

    private function maintainer(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('parties.party.manage');
        $user = User::factory()->create();
        $user->givePermissionTo('parties.party.manage');

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Sara Khan', 'title' => 'Purchasing lead', 'email' => 'sara@acme.test', 'phone' => '0300 1234567'], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson("/api/v1/parties/{$this->party->id}/contacts")->assertUnauthorized();
        $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())->assertUnauthorized();
    }

    public function test_someone_with_no_party_duty_cannot_read_or_write_contacts(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->getJson("/api/v1/parties/{$this->party->id}/contacts")->assertForbidden();
        $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())->assertForbidden();
    }

    public function test_a_party_maintainer_adds_lists_and_changes_contacts(): void
    {
        $this->actingAs($this->maintainer());

        $id = $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())
            ->assertCreated()->assertJsonPath('data.name', 'Sara Khan')->assertJsonPath('data.party_id', $this->party->id)->json('data.id');
        $this->getJson("/api/v1/parties/{$this->party->id}/contacts")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'sara@acme.test');
        $this->getJson("/api/v1/parties/{$this->party->id}/contacts?filter[search]=0300")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/parties/{$this->party->id}/contacts?filter[search]=nobody-like-this")->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/party-contacts/{$id}", ['phone' => '021 111 222'])->assertOk()->assertJsonPath('data.phone', '021 111 222');
        $this->assertDatabaseHas('party_contacts', ['id' => $id, 'phone' => '021 111 222']);
    }

    public function test_a_contact_needs_a_name_and_a_valid_email_and_cannot_be_moved_to_another_party(): void
    {
        $this->actingAs($this->maintainer());
        $id = $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())->json('data.id');

        $this->postJson("/api/v1/parties/{$this->party->id}/contacts", ['title' => 'No name'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload(['email' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson("/api/v1/party-contacts/{$id}", ['party_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('party_id');
    }

    public function test_only_one_contact_is_primary_at_a_time(): void
    {
        $this->actingAs($this->maintainer());
        $first = $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload(['is_primary' => true]))->json('data.id');
        $second = $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload(['name' => 'Omar', 'email' => 'omar@acme.test', 'is_primary' => true]))->json('data.id');

        $this->assertDatabaseHas('party_contacts', ['id' => $first, 'is_primary' => false]);
        $this->assertDatabaseHas('party_contacts', ['id' => $second, 'is_primary' => true]);

        $this->patchJson("/api/v1/party-contacts/{$first}", ['is_primary' => true])->assertOk();
        $this->assertDatabaseHas('party_contacts', ['id' => $second, 'is_primary' => false]);
        $this->assertSame($first, $this->getJson("/api/v1/parties/{$this->party->id}/contacts")->json('data.0.id'), 'the primary contact is listed first');
    }

    public function test_another_companys_party_and_contacts_are_unreachable(): void
    {
        $this->actingAs($this->maintainer());
        $id = $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())->json('data.id');

        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->getJson("/api/v1/parties/{$this->party->id}/contacts")->assertNotFound();
        $this->postJson("/api/v1/parties/{$this->party->id}/contacts", $this->payload())->assertNotFound();
        $this->patchJson("/api/v1/party-contacts/{$id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertDatabaseHas('party_contacts', ['id' => $id, 'name' => 'Sara Khan']);
    }
}
