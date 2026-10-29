<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M1 — `GET quotations/{revision}/editable`: the draft in the shape the PATCH accepts, for the preparer, independent of `view`, working for an incomplete draft,
 * carrying entered values only, and non-disclosing.
 */
class QuotationDraftEditableApiTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = PermissionVocabulary::QUOTATION_VIEW;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private User $preparer;

    private Party $party;

    private string $today;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([self::VIEW, self::PREPARE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->today = now(Company::findOrFail(1)->timezone)->format('Y-m-d');
        $this->party = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->preparer = User::factory()->create();
        $this->preparer->givePermissionTo(self::PREPARE);
    }

    private function activate(): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, [self::VIEW, self::PREPARE], true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function create(array $body): int
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $this->key()];

        return (int) $this->call('POST', '/api/v1/quotations', [], [], [], $server, $body === [] ? '{}' : json_encode($body))->assertCreated()->json('data.revision_id');
    }

    private function update(int $revision, array $body, int $generation): TestResponse
    {
        return $this->patchJson("/api/v1/quotations/{$revision}", $body + ['expected_generation' => $generation], ['Idempotency-Key' => $this->key()]);
    }

    private function line(string $description, array $more = []): array
    {
        return $more + ['source_kind' => 'MANUAL', 'description' => $description, 'manual_measure' => 'job', 'offered_quantity' => '2', 'unit_price' => '15.5000'];
    }

    public function test_every_caller_is_refused_while_the_duties_are_staged(): void
    {
        Sanctum::actingAs($this->preparer);

        $this->getJson('/api/v1/quotations/1/editable')->assertForbidden();
    }

    public function test_unauthenticated_callers_are_refused(): void
    {
        $this->getJson('/api/v1/quotations/1/editable')->assertUnauthorized();
    }

    public function test_it_is_the_preparers_duty_and_independent_of_view(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create(['recipient_party_id' => $this->party->id]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(self::VIEW);

        $this->getJson("/api/v1/quotations/{$revision}/editable")->assertOk();
        $this->getJson("/api/v1/quotations/{$revision}")->assertForbidden();
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/quotations/{$revision}/editable")->assertForbidden();
    }

    public function test_an_incomplete_draft_can_be_reopened_although_it_cannot_be_previewed(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create([]);

        $this->getJson("/api/v1/quotations/{$revision}/preview")->assertStatus(422)->assertJsonPath('reason_code', 'RECIPIENT_REQUIRED');
        $this->getJson("/api/v1/quotations/{$revision}/editable")->assertOk()
            ->assertJsonPath('data.recipient_party_id', null)->assertJsonPath('data.pricing_date', null)->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.state', 'DRAFT')->assertJsonPath('data.currency_id', Company::findOrFail(1)->base_currency_id);
    }

    public function test_the_payload_has_exactly_the_writable_fields_and_no_derived_or_cost_data(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today, 'tax_estimate_date' => $this->today, 'valid_through_local_date' => '2099-01-01']);
        $this->update($revision, ['add_lines' => [$this->line('Survey', ['scope_ref' => 'scope-a', 'section_title' => 'Site'])]], QuotationRevision::findOrFail($revision)->input_generation)->assertOk();

        $data = $this->getJson("/api/v1/quotations/{$revision}/editable")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['revision_id', 'family_id', 'state', 'input_generation', 'recipient_party_id', 'currency_id', 'pricing_date', 'tax_estimate_date', 'valid_through_local_date', 'current_opportunity_id', 'lines'], array_keys($data));
        $this->assertEqualsCanonicalizing(['id', 'scope_ref', 'section_title', 'description', 'membership', 'choice_group_ref', 'is_baseline', 'source_kind', 'item_id', 'manual_measure', 'offered_quantity', 'offered_uom_id', 'unit_price', 'sort_order'], array_keys($data['lines'][0]));
        $this->assertSame('scope-a', $data['lines'][0]['scope_ref']);
        $this->assertSame('15.5000', $data['lines'][0]['unit_price']);
        $this->assertSame('2.0000', $data['lines'][0]['offered_quantity']);
        $this->assertSame('2099-01-01', $data['valid_through_local_date']);
    }

    public function test_what_it_returns_can_be_written_back_with_the_generation_it_reports(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today]);
        $this->update($revision, ['add_lines' => [
            $this->line('Base work', ['scope_ref' => 'base']),
            $this->line('Option A', ['scope_ref' => 'opt-a', 'membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'options', 'is_baseline' => true]),
        ]], QuotationRevision::findOrFail($revision)->input_generation)->assertOk();

        $form = $this->getJson("/api/v1/quotations/{$revision}/editable")->assertOk()->json('data');
        $option = collect($form['lines'])->firstWhere('scope_ref', 'opt-a');
        $this->assertSame('options', $option['choice_group_ref']);
        $this->assertSame('CHOOSE_ONE', $option['membership']);

        $this->update($revision, ['update_lines' => [['id' => $option['id'], 'description' => 'Option A (revised)', 'choice_group_ref' => $option['choice_group_ref'], 'membership' => $option['membership']]]], $form['input_generation'])->assertOk();

        $again = $this->getJson("/api/v1/quotations/{$revision}/editable")->assertOk()->json('data');
        $this->assertGreaterThan($form['input_generation'], $again['input_generation']);
        $this->assertSame('Option A (revised)', collect($again['lines'])->firstWhere('id', $option['id'])['description']);
        $this->assertSame('options', collect($again['lines'])->firstWhere('id', $option['id'])['choice_group_ref']);
        $this->assertSame('base', collect($again['lines'])->firstWhere('description', 'Base work')['scope_ref']);
    }

    public function test_a_cancelled_draft_cannot_be_reopened(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create([]);
        DB::table('quotation_revisions')->where('id', $revision)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->preparer->id, 'cancellation_reason' => 'Withdrawn']);

        $this->getJson("/api/v1/quotations/{$revision}/editable")->assertStatus(422)->assertJsonPath('reason_code', 'QUOTATION_NOT_EDITABLE');
    }

    public function test_an_unknown_or_foreign_revision_is_a_non_disclosing_404(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create([]);
        config(['app.debug' => false]);
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $unknown = $this->getJson('/api/v1/quotations/999999/editable')->assertNotFound()->json('message');
        $foreign = $this->getJson("/api/v1/quotations/{$revision}/editable")->assertNotFound()->json('message');
        $this->assertSame($unknown, str_replace((string) $revision, '999999', $foreign));
    }

    public function test_a_preparer_limited_to_my_units_is_refused(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->create([]);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited preparer', 'guard_name' => 'web']);
        $role->givePermissionTo(self::PREPARE);
        $limited = User::factory()->create();
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        $limited->givePermissionTo(self::VIEW);
        Sanctum::actingAs($limited);

        $this->getJson("/api/v1/quotations/{$revision}/editable")->assertForbidden();
    }
}
