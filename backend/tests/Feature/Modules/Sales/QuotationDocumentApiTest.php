<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
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
}
