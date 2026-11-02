<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * The quotation document HTTP contract: default-deny while the duties are STAGED, then (duties active) generate / status / download / history / check and the manual delivery
 * record, with safe resources, non-disclosing 404s and idempotent writes. Nothing is emailed or linked; the PDF is streamed after the owner's decision.
 */
class QuotationDocumentApiTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const DUTIES = [P::DOCUMENT_GENERATE, P::DOCUMENT_VIEW, P::DOCUMENT_DOWNLOAD, P::DOCUMENT_REPAIR, P::DELIVERY_VIEW, P::DELIVERY_RECORD, P::QUOTATION_VIEW];

    private User $clerk;

    private int $revision;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->setUpIssuing();
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->clerk = User::factory()->create();
        $this->clerk->givePermissionTo(self::DUTIES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $draft = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $draft->id, QuotationRevision::findOrFail($draft->id)->input_generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->revision = $draft->id;
    }

    private function activate(): void
    {
        $names = [...self::DUTIES, P::QUOTATION_PREPARE, P::QUOTATION_ISSUE];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/quotations/{$this->revision}{$tail}";
    }

    public function test_everything_is_refused_while_the_duties_are_staged_and_unauthenticated_callers_get_401(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson($this->url('/document'))->assertUnauthorized();
        Sanctum::actingAs($this->clerk);
        $this->getJson($this->url('/document'))->assertForbidden();
        $this->postJson($this->url('/document'), [], ['Idempotency-Key' => 'k'])->assertForbidden();
        $this->getJson($this->url('/document/download'))->assertForbidden();
        $this->getJson($this->url('/document/history'))->assertForbidden();
        $this->postJson($this->url('/document/check'))->assertForbidden();
        $this->getJson($this->url('/deliveries'))->assertForbidden();
        $this->postJson($this->url('/deliveries'), ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'A'], ['Idempotency-Key' => 'k'])->assertForbidden();
    }

    public function test_the_whole_flow_generates_streams_records_and_leaks_nothing_private(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $this->getJson($this->url('/document'))->assertOk()->assertJsonPath('data.state', 'PENDING');
        $this->postJson($this->url('/document'))->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');

        $made = $this->postJson($this->url('/document'), [], ['Idempotency-Key' => 'gen-1'])->assertOk()->assertJsonPath('data.state', 'READY')->assertJsonPath('data.artefact.available', true);
        $this->postJson($this->url('/document'), [], ['Idempotency-Key' => 'gen-2'])->assertOk()->assertJsonPath('data.artefact.id', $made->json('data.artefact.id'));

        $file = $this->get($this->url('/document/download'))->assertOk();
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="', (string) $file->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF-', $file->streamedContent());
        $this->get($this->url('/document/download?intent=print'))->assertOk();

        $history = $this->getJson($this->url('/document/history'))->assertOk();
        $this->assertSame(['GENERATED', 'DOWNLOAD_DELIVERED', 'PRINT_DELIVERED'], array_column($history->json('data'), 'event'));
        $this->postJson($this->url('/document/check'))->assertOk()->assertJsonPath('data.outcome', 'ok');

        $body = ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'Mr Customer', 'note' => 'copy kept'];
        $first = $this->postJson($this->url('/deliveries'), $body, ['Idempotency-Key' => 'd-1'])->assertCreated()->assertJsonPath('data.channel', 'HAND');
        $this->postJson($this->url('/deliveries'), $body, ['Idempotency-Key' => 'd-1'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postJson($this->url('/deliveries'), [...$body, 'recipient_name' => 'Other'], ['Idempotency-Key' => 'd-1'])->assertStatus(409)->assertJsonPath('reason_code', 'DELIVERY_KEY_REUSED');
        $this->postJson($this->url('/deliveries'), [...$body, 'channel' => 'EMAIL'], ['Idempotency-Key' => 'd-2'])->assertStatus(422);
        $this->postJson($this->url('/deliveries'), [...$body, 'delivered_on' => now()->addDays(9)->format('Y-m-d')], ['Idempotency-Key' => 'd-3'])->assertStatus(422);
        $this->postJson($this->url('/deliveries'), [...$body, 'recipient_name' => 'Ms Customer', 'corrects_id' => $first->json('data.id')], ['Idempotency-Key' => 'd-4'])->assertCreated();
        $this->getJson($this->url('/deliveries'))->assertOk()->assertJsonCount(2, 'data');

        $all = json_encode([$made->json(), $history->json(), $this->getJson($this->url('/deliveries'))->json()]);
        foreach (['storage_key', 'storage_disk', 'sha256', 'checksum', '/storage/', 'signed_url'] as $private) {
            $this->assertStringNotContainsString($private, $all);
        }
    }

    public function test_an_unknown_or_unissued_quotation_is_a_plain_404_and_a_non_viewer_is_refused(): void
    {
        $this->activate();
        Sanctum::actingAs($this->clerk);
        $draft = $this->preparedDraft();
        foreach ([$draft->id, 999999] as $id) {
            $this->getJson("/api/v1/quotations/{$id}/document")->assertNotFound();
            $this->get("/api/v1/quotations/{$id}/document/download")->assertNotFound();
            $this->getJson("/api/v1/quotations/{$id}/deliveries")->assertNotFound();
        }
        $noView = User::factory()->create();
        $noView->givePermissionTo(array_diff(self::DUTIES, [P::QUOTATION_VIEW]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($noView);
        $this->getJson($this->url('/document'))->assertForbidden();
        $this->get($this->url('/document/download'))->assertForbidden();
    }

    /**
     * Every document and delivery route, as (method, tail, body, needs a request key). One shared list so a new route cannot be added without these probes.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
     */
    private function routes(): array
    {
        return [
            ['GET', '/document', [], false],
            ['POST', '/document', [], true],
            ['GET', '/document/download', [], false],
            ['GET', '/document/history', [], false],
            ['POST', '/document/check', [], false],
            ['GET', '/deliveries', [], false],
            ['POST', '/deliveries', ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'Probe'], true],
        ];
    }

    /**
     * What a production caller can observe of one request. A fresh request key per call: a refusal must not depend on the key.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: string}
     */
    private function observe(int $revision, string $method, string $tail, array $body, bool $keyed): array
    {
        $response = $this->json($method, "/api/v1/quotations/{$revision}{$tail}", $body, $keyed ? ['Idempotency-Key' => 'probe-'.bin2hex(random_bytes(6))] : []);

        // Only what a production client sees: the test app runs with debug on, which appends the exception, file, line and trace, and
        // those differ by call site, not by revision.
        $json = json_decode((string) $response->getContent(), true);
        $body = is_array($json) ? json_encode(array_intersect_key($json, array_flip(['message', 'reason_code', 'errors', 'data'])), JSON_THROW_ON_ERROR)
            : (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    /** @return array{events: int, deliveries: int, intents: int} the evidence a refused probe must never write */
    private function writes(): array
    {
        // Raw tables, not models: a model count is scoped to the current company and would miss a write in another one.
        return ['events' => DB::table('document_access_events')->count(), 'deliveries' => DB::table('quotation_document_deliveries')->count(),
            'intents' => DB::table('document_generation_intents')->count()];
    }

    /** A generated, downloaded and delivered document, so the probes below aim at a revision with real evidence behind it. */
    private function documentWithEvidence(): void
    {
        Sanctum::actingAs($this->clerk);
        $this->postJson($this->url('/document'), [], ['Idempotency-Key' => 'gen-evidence'])->assertOk()->assertJsonPath('data.state', 'READY');
        $this->get($this->url('/document/download'))->assertOk();
        $this->postJson($this->url('/deliveries'), ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'Mr Customer'], ['Idempotency-Key' => 'del-evidence'])
            ->assertCreated();
    }

    public function test_a_caller_without_quotation_view_cannot_tell_an_existing_revision_from_an_unknown_one(): void
    {
        $this->activate();
        $this->documentWithEvidence();
        $noView = User::factory()->create();
        $noView->givePermissionTo(array_values(array_diff(self::DUTIES, [P::QUOTATION_VIEW])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($noView);
        $before = $this->writes();

        foreach ($this->routes() as [$method, $tail, $body, $keyed]) {
            $existing = $this->observe($this->revision, $method, $tail, $body, $keyed);
            $unknown = $this->observe(999999, $method, $tail, $body, $keyed);
            $this->assertSame(403, $existing['status'], "{$method} {$tail}: a caller without the independent quotation view is refused");
            $this->assertSame($unknown, $existing, "{$method} {$tail}: an existing revision must look exactly like an unknown one");
        }
        $this->assertSame($before, $this->writes(), 'a refused probe writes no access event, delivery or generation intent');
    }

    public function test_another_companys_revision_and_another_companys_member_are_isolated_at_every_route(): void
    {
        $this->activate();
        $this->documentWithEvidence();
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'base_currency_id' => Company::findOrFail(1)->base_currency_id, 'created_at' => now(), 'updated_at' => now()]);
        // Inserted directly: the company trait (rightly) refuses to create another company's row while this company is active.
        $outsiderId = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'Outsider', 'email' => 'outsider@other.test', 'password' => 'x', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now()]);
        $outsider = User::withoutGlobalScopes()->findOrFail($outsiderId);
        $outsider->givePermissionTo(self::DUTIES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $foreignFamily = DB::table('quotation_families')->insertGetId(['company_id' => $other, 'created_by' => $outsider->id, 'created_at' => now(), 'updated_at' => now()]);
        $foreignRevision = DB::table('quotation_revisions')->insertGetId(['company_id' => $other, 'family_id' => $foreignFamily, 'currency_id' => Company::findOrFail(1)->base_currency_id,
            'created_by' => $outsider->id, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->writes();

        // A member of this company, holding every duty, probing another company's revision sees exactly what an unknown id shows: not found.
        Sanctum::actingAs($this->clerk);
        foreach ($this->routes() as [$method, $tail, $body, $keyed]) {
            $foreign = $this->observe($foreignRevision, $method, $tail, $body, $keyed);
            $unknown = $this->observe(999999, $method, $tail, $body, $keyed);
            $this->assertSame(404, $foreign['status'], "{$method} {$tail}: another company's revision is not found");
            $this->assertSame($unknown, $foreign, "{$method} {$tail}: another company's revision must look exactly like an unknown one");
        }

        // A member of another company, holding every duty there, reaches nothing here, and cannot tell this company's issued revision from an unknown id.
        Sanctum::actingAs($outsider);
        foreach ($this->routes() as [$method, $tail, $body, $keyed]) {
            $here = $this->observe($this->revision, $method, $tail, $body, $keyed);
            $unknown = $this->observe(999999, $method, $tail, $body, $keyed);
            $this->assertContains($here['status'], [403, 404], "{$method} {$tail}: an outsider is refused");
            $this->assertSame($unknown, $here, "{$method} {$tail}: an outsider must not learn that this company's revision exists");
        }

        $this->assertSame($before, $this->writes(), 'no cross-company probe wrote an access event, a delivery or a generation intent');
    }

    public function test_document_routes_follow_the_quotation_family_policy_for_own_and_all_holders(): void
    {
        $this->activate();
        $this->documentWithEvidence(); // the clerk holds every duty unrestricted (ALL) and reaches the document
        $documentDuties = array_values(array_diff(self::DUTIES, [P::QUOTATION_VIEW]));

        // OWN view, and the caller is the quotation's current responsible owner (the maker created the draft): reached.
        $this->maker->givePermissionTo([...$documentDuties, P::QUOTATION_VIEW]);
        AccessScopeLimit::create(['user_id' => $this->maker->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        // OWN view, but someone else owns the quotation: exactly what an unknown id shows.
        $stranger = User::factory()->create();
        $stranger->givePermissionTo([...$documentDuties, P::QUOTATION_VIEW]);
        AccessScopeLimit::create(['user_id' => $stranger->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        // A document duty limited to OWN: document duties have no family scope, so the limit is refused outright, never widened.
        $limitedDuty = User::factory()->create();
        $limitedDuty->givePermissionTo(self::DUTIES);
        AccessScopeLimit::create(['user_id' => $limitedDuty->id, 'permission' => P::DOCUMENT_VIEW, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = $this->writes();

        Sanctum::actingAs($this->maker);
        $this->getJson($this->url('/document'))->assertOk()->assertJsonPath('data.state', 'READY');
        $this->getJson($this->url('/deliveries'))->assertOk()->assertJsonCount(1, 'data');
        // History and download also pass the Documents owner gate, which must apply the same family policy (not a company-wide view).
        $this->getJson($this->url('/document/history'))->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->get($this->url('/document/download'))->assertOk()->streamedContent());
        $before = $this->writes(); // the owner's download is a recorded hand-off

        Sanctum::actingAs($stranger);
        foreach ($this->routes() as [$method, $tail, $body, $keyed]) {
            $hidden = $this->observe($this->revision, $method, $tail, $body, $keyed);
            $unknown = $this->observe(999999, $method, $tail, $body, $keyed);
            $this->assertSame(404, $hidden['status'], "{$method} {$tail}: a quotation outside the caller's OWN scope is not found");
            $this->assertSame($unknown, $hidden, "{$method} {$tail}: a hidden quotation must look exactly like an unknown one");
        }

        Sanctum::actingAs($limitedDuty);
        $this->assertSame($this->observe(999999, 'GET', '/document', [], false), $refused = $this->observe($this->revision, 'GET', '/document', [], false));
        $this->assertSame(403, $refused['status']);

        $this->assertSame($before, $this->writes(), 'reads write nothing, and no refused or hidden probe writes anything');
    }
}
