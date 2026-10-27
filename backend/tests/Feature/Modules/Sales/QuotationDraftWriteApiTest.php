<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QL1b-2 — the draft WRITE adapters (POST quotations, PATCH quotations/{revision}): default-deny while STAGED, `prepare` only, the required Idempotency-Key and
 * exact replay, expected-generation concurrency, the documented status of each refusal, non-disclosing 404, strict bodies and a body that carries nothing but ids.
 */
class QuotationDraftWriteApiTest extends TestCase
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
        $this->preparer->givePermissionTo([self::PREPARE]);
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

    /** @return array<string, mixed> */
    private function header(array $more = []): array
    {
        return $more + ['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today];
    }

    private function create(?string $key = null, ?array $body = null): TestResponse
    {
        return $this->postJson('/api/v1/quotations', $body ?? $this->header(), ['Idempotency-Key' => $key ?? $this->key()]);
    }

    private function update(int $revision, array $body, ?string $key = null, ?int $generation = null): TestResponse
    {
        $generation ??= QuotationRevision::findOrFail($revision)->input_generation;

        return $this->patchJson("/api/v1/quotations/{$revision}", $body + ['expected_generation' => $generation], ['Idempotency-Key' => $key ?? $this->key()]);
    }

    private function line(string $description = 'Survey', string $quantity = '1'): array
    {
        return ['source_kind' => 'MANUAL', 'description' => $description, 'manual_measure' => 'job', 'offered_quantity' => $quantity, 'unit_price' => null];
    }

    private function draft(): int
    {
        return (int) $this->create()->assertCreated()->json('data.revision_id');
    }

    public function test_every_caller_is_refused_while_the_duties_are_staged(): void
    {
        Sanctum::actingAs($this->preparer);

        $this->create()->assertForbidden();
        $this->patchJson('/api/v1/quotations/1', ['expected_generation' => 1, 'description' => 'x'], ['Idempotency-Key' => 'k'])->assertForbidden();
        $this->assertSame(0, QuotationRevision::query()->count());
        $this->assertSame(0, QuotationOperationReceipt::query()->count());
    }

    public function test_unauthenticated_callers_are_refused(): void
    {
        $this->postJson('/api/v1/quotations', $this->header(), ['Idempotency-Key' => 'k'])->assertUnauthorized();
        $this->patchJson('/api/v1/quotations/1', ['expected_generation' => 1], ['Idempotency-Key' => 'k'])->assertUnauthorized();
    }

    public function test_a_view_only_holder_cannot_write(): void
    {
        $this->activate();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(self::VIEW);
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        Sanctum::actingAs($viewer);

        $this->create()->assertForbidden();
        $this->update($revision, ['add_lines' => [$this->line()]])->assertForbidden();
        $this->assertSame(1, QuotationRevision::query()->count());
    }

    public function test_a_prepare_holder_creates_and_the_body_carries_only_ids_and_the_generation(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);

        $response = $this->create()->assertCreated()->assertJsonPath('data.replayed', false)->assertHeaderMissing('Idempotency-Replayed');

        $this->assertEqualsCanonicalizing(['family_id', 'revision_id', 'input_generation', 'replayed'], array_keys($response->json('data')));
        $revision = QuotationRevision::findOrFail($response->json('data.revision_id'));
        $this->assertSame('DRAFT', $revision->state);
        $this->assertSame($revision->input_generation, $response->json('data.input_generation'));
        $this->assertSame($this->preparer->id, $revision->created_by);
    }

    public function test_an_exact_retry_replays_the_stored_result(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $first = $this->create('same-key')->assertCreated();

        $again = $this->create('same-key')->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.revision_id'), $again->json('data.revision_id'));
        $this->assertSame($first->json('data.family_id'), $again->json('data.family_id'));
        $this->assertSame(1, QuotationRevision::query()->count());
    }

    public function test_a_key_already_used_for_a_different_request_is_a_409(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $this->create('shared-key')->assertCreated();

        $this->create('shared-key', $this->header(['valid_through_local_date' => '2099-01-01']))->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->assertSame(1, QuotationRevision::query()->count());
    }

    public function test_the_idempotency_key_is_required_on_both_writes(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();

        $this->postJson('/api/v1/quotations', $this->header())->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postJson('/api/v1/quotations', $this->header(), ['Idempotency-Key' => '  '])->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postJson('/api/v1/quotations', $this->header(), ['Idempotency-Key' => str_repeat('k', 86)])->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $generation = QuotationRevision::findOrFail($revision)->input_generation;
        $this->patchJson("/api/v1/quotations/{$revision}", ['expected_generation' => $generation, 'add_lines' => [$this->line()]])->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->assertSame(1, QuotationRevision::query()->count());
        $this->assertSame($generation, QuotationRevision::findOrFail($revision)->input_generation);
    }

    public function test_an_update_changes_the_draft_and_advances_the_generation(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $before = QuotationRevision::findOrFail($revision)->input_generation;

        $response = $this->update($revision, ['add_lines' => [$this->line()], 'valid_through_local_date' => '2099-01-01'])->assertOk()->assertJsonPath('data.revision_id', $revision)->assertJsonPath('data.replayed', false);

        $this->assertGreaterThan($before, $response->json('data.input_generation'));
        $this->assertSame($response->json('data.input_generation'), QuotationRevision::findOrFail($revision)->input_generation);
        $this->assertSame(1, QuotationRevision::findOrFail($revision)->lines()->count());
    }

    public function test_an_update_retry_replays_without_applying_twice(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $generation = QuotationRevision::findOrFail($revision)->input_generation;
        $first = $this->update($revision, ['add_lines' => [$this->line()]], 'update-key', $generation)->assertOk();

        $again = $this->update($revision, ['add_lines' => [$this->line()]], 'update-key', $generation)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.input_generation'), $again->json('data.input_generation'));
        $this->assertSame(1, QuotationRevision::findOrFail($revision)->lines()->count());
        $this->update($revision, ['add_lines' => [$this->line('Different')]], 'update-key', $generation)->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
    }

    public function test_the_expected_generation_is_required_and_a_moved_draft_is_a_422_that_changes_nothing(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $generation = QuotationRevision::findOrFail($revision)->input_generation;
        $this->update($revision, ['add_lines' => [$this->line()]], null, $generation)->assertOk();

        $this->patchJson("/api/v1/quotations/{$revision}", ['add_lines' => [$this->line('Second')]], ['Idempotency-Key' => $this->key()])
            ->assertStatus(422)->assertJsonValidationErrors('expected_generation');
        $this->update($revision, ['add_lines' => [$this->line('Second')]], null, $generation)
            ->assertStatus(422)->assertJsonPath('reason_code', 'QUOTATION_DRAFT_STALE');
        $this->assertSame(1, QuotationRevision::findOrFail($revision)->lines()->count());
    }

    public function test_an_unknown_field_is_refused_never_ignored(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();

        $this->create(null, $this->header(['state' => 'ISSUED']))->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->create(null, $this->header(['company_id' => 2, 'created_by' => 99]))->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->update($revision, ['state' => 'ISSUED'])->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->update($revision, ['add_lines' => [$this->line() + ['cost' => '1.0000', 'approved' => true]]])->assertStatus(422)->assertJsonPath('reason_code', 'INVALID_INPUT');
        $this->assertSame(1, QuotationRevision::query()->count());
        $this->assertSame(0, QuotationRevision::findOrFail($revision)->lines()->count());
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);

        $this->post('/api/v1/quotations', ['recipient_party_id' => $this->party->id], ['Idempotency-Key' => 'form-key', 'Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertSame(0, QuotationRevision::query()->count());
    }

    public function test_a_cancelled_draft_cannot_be_changed(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        DB::table('quotation_revisions')->where('id', $revision)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->preparer->id, 'cancellation_reason' => 'Withdrawn']);

        $this->update($revision, ['add_lines' => [$this->line()]], null, 1)->assertStatus(422)->assertJsonPath('reason_code', 'QUOTATION_NOT_EDITABLE');
    }

    public function test_an_unknown_or_foreign_revision_is_a_non_disclosing_404(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
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

        $unknown = $this->update(999999, ['add_lines' => [$this->line()]], null, 1)->assertNotFound()->json('message');
        $foreign = $this->update($revision, ['add_lines' => [$this->line()]], null, 1)->assertNotFound()->json('message');
        $this->assertSame($unknown, str_replace((string) $revision, '999999', $foreign));
    }

    /**
     * A person whose `prepare` is limited to "my units" (through a role, or directly) is refused outright — the quotation record scope is not built, so a limit must never be
     * read as company-wide — however many unrelated or independent unrestricted duties they also hold.
     *
     * @return array<string, array{0: string}>
     */
    public static function limitedPreparers(): array
    {
        return ['limited by role' => ['role'], 'limited by role with unrestricted view' => ['role+view'], 'limited by role with an unrelated unrestricted duty' => ['role+stage'], 'limited direct grant' => ['direct']];
    }

    private function limitedPreparer(string $kind): User
    {
        $user = User::factory()->create();
        if ($kind === 'direct') {
            $user->givePermissionTo(self::PREPARE);
            AccessScopeLimit::create(['user_id' => $user->id, 'permission' => self::PREPARE, 'scope' => 'MY_UNITS']);

            return $user;
        }
        $role = Role::create(['team_id' => 1, 'name' => 'Limited preparer '.$kind, 'guard_name' => 'web']);
        $role->givePermissionTo(self::PREPARE);
        $user->assignRole($role);
        AccessScopeLimit::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        if ($kind === 'role+view') {
            $user->givePermissionTo(self::VIEW);
        }
        if ($kind === 'role+stage') {
            Permission::findOrCreate(PermissionVocabulary::STAGE_MANAGE, 'web');
            $user->givePermissionTo(PermissionVocabulary::STAGE_MANAGE);
        }

        return $user;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('limitedPreparers')]
    public function test_a_limited_preparer_cannot_write_through_either_route_or_either_action(string $kind): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $generation = QuotationRevision::findOrFail($revision)->input_generation;
        $revisions = QuotationRevision::query()->count();
        $receipts = QuotationOperationReceipt::query()->count();
        $limited = $this->limitedPreparer($kind);
        Sanctum::actingAs($limited);

        $this->create()->assertForbidden();
        $this->update($revision, ['add_lines' => [$this->line()]], null, $generation)->assertForbidden();
        foreach ([
            fn () => app(CreateQuotationDraft::class)($limited, 'direct-create', $this->header()),
            fn () => app(UpdateQuotationDraft::class)($limited, $revision, $generation, 'direct-update', ['add_lines' => [$this->line()]]),
        ] as $direct) {
            try {
                $direct();
                $this->fail('A limited preparer reached an Action.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($revisions, QuotationRevision::query()->count(), 'No draft was written.');
        $this->assertSame($receipts, QuotationOperationReceipt::query()->count(), 'No receipt was written.');
        $this->assertSame($generation, QuotationRevision::findOrFail($revision)->input_generation);
        $this->assertSame(0, QuotationRevision::findOrFail($revision)->lines()->count());
    }

    public function test_a_body_that_is_not_a_json_object_is_refused_and_writes_nothing(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $generation = QuotationRevision::findOrFail($revision)->input_generation;
        $revisions = QuotationRevision::query()->count();
        $receipts = QuotationOperationReceipt::query()->count();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'raw-key'];

        foreach (['{broken', '', 'null', '5', '"text"', '[]', '[1,2]'] as $raw) {
            $this->call('POST', '/api/v1/quotations', [], [], [], $server, $raw)->assertStatus(422)->assertJsonValidationErrors('body');
            $this->call('PATCH', "/api/v1/quotations/{$revision}", [], [], [], $server, $raw)->assertStatus(422)->assertJsonValidationErrors('body');
        }

        $this->assertSame($revisions, QuotationRevision::query()->count());
        $this->assertSame($receipts, QuotationOperationReceipt::query()->count());
        $this->assertSame($generation, QuotationRevision::findOrFail($revision)->input_generation);
    }

    public function test_an_empty_json_object_is_still_a_valid_create(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);

        $this->call('POST', '/api/v1/quotations', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'empty-object'], '{}')->assertCreated();
    }

    public function test_the_typed_body_rejects_a_wrongly_typed_value_before_any_write(): void
    {
        $this->activate();
        Sanctum::actingAs($this->preparer);
        $revision = $this->draft();
        $receipts = QuotationOperationReceipt::query()->count();

        $this->update($revision, ['add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => 1.5]]])
            ->assertStatus(422)->assertJsonValidationErrors('add_lines.0.offered_quantity');
        $this->create(null, $this->header(['pricing_date' => 'not-a-date']))->assertStatus(422)->assertJsonValidationErrors('pricing_date');
        $this->assertSame($receipts, QuotationOperationReceipt::query()->count());
    }
}
