<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Documents\Models\DocumentAccessEvent;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use App\Modules\Sales\Actions\DownloadQuotationDocument;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\ReadQuotationDocumentDeliveries;
use App\Modules\Sales\Actions\ReadQuotationDocumentHistory;
use App\Modules\Sales\Actions\ReadQuotationDocumentStatus;
use App\Modules\Sales\Actions\RecordManualQuotationDelivery;
use App\Modules\Sales\Actions\RepairQuotationDocument;
use App\Modules\Sales\Actions\RequestQuotationDocument;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Documents\PdfInspector;
use Tests\TestCase;

/**
 * The complete quotation document feature at the Action level: an issued quotation's official PDF is generated from its frozen issue, stored and verified through Files,
 * handed out only to an authorised holder with a hand-off record, recorded as manually delivered, and recovered from a backup copy. Every Action is default-deny by duty
 * AND quotation visibility; an unknown, foreign or unissued revision is the same "not found". No route yet.
 */
class QuotationDocumentFeatureTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const CLERK_DUTIES = [P::DOCUMENT_GENERATE, P::DOCUMENT_VIEW, P::DOCUMENT_DOWNLOAD, P::DELIVERY_VIEW, P::DELIVERY_RECORD, P::QUOTATION_VIEW];

    private User $clerk;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->setUpIssuing();
        $all = [...self::CLERK_DUTIES, P::DOCUMENT_REPAIR, P::QUOTATION_PREPARE, P::QUOTATION_ISSUE];
        foreach ($all as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->clerk = User::factory()->create();
        $this->clerk->givePermissionTo(self::CLERK_DUTIES);
        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo([P::DOCUMENT_REPAIR, P::DOCUMENT_VIEW, P::QUOTATION_VIEW]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $duties = array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $all, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function issued(): int
    {
        $revision = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $revision->id;
    }

    private function ready(int $revision): array
    {
        return app(RequestQuotationDocument::class)($this->clerk, $revision, $this->issueKey());
    }

    private function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        } catch (AuthorizationException) {
            return 'FORBIDDEN';
        }

        return 'NO_REFUSAL';
    }

    private function stream(string $bytes)
    {
        $s = fopen('php://temp', 'r+');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    public function test_an_issued_quotation_gets_its_official_pdf_from_the_frozen_issue_and_it_downloads_with_a_record(): void
    {
        $revision = $this->issued();
        $this->assertSame('PENDING', app(ReadQuotationDocumentStatus::class)($this->clerk, $revision)['state']);

        $status = $this->ready($revision);
        $this->assertSame(['READY', true, 1], [$status['state'], $status['artefact']['available'], $status['artefact']['pages']]);
        $this->assertSame(['id', 'pages', 'size_bytes', 'media_type', 'created_at', 'available'], array_keys($status['artefact']), 'a safe status: no key, path or hash');

        $download = app(DownloadQuotationDocument::class)($this->clerk, $revision);
        $bytes = stream_get_contents($download->stream);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $text = (new PdfInspector($bytes))->allText();
        $this->assertStringContainsString('QTN-00001', $text);
        $this->assertStringContainsString('Survey', $text);
        $this->assertSame('application/pdf', $download->headers()['Content-Type']);
        $this->assertSame([['DOWNLOAD', 'DELIVERED']], DocumentAccessEvent::query()->get()->map(fn ($e) => [$e->access_kind, $e->outcome])->all());
        app(DownloadQuotationDocument::class)($this->clerk, $revision, 'PRINT');

        $history = app(ReadQuotationDocumentHistory::class)($this->clerk, $revision);
        $this->assertSame(['GENERATED', 'DOWNLOAD_DELIVERED', 'PRINT_DELIVERED'], array_column($history, 'event'));
    }

    public function test_requesting_again_is_idempotent_and_a_failed_generation_is_retried_under_a_new_key(): void
    {
        $revision = $this->issued();
        DocumentArtefact::creating(fn () => throw new \RuntimeException('crash while publishing'));
        try {
            $failed = $this->ready($revision);
        } finally {
            // Removes the test's listener and the model's own (company scoping) with it, so the model is booted afresh.
            DocumentArtefact::getEventDispatcher()->forget('eloquent.creating: '.DocumentArtefact::class);
            Model::clearBootedModels();
        }
        $this->assertSame(['FAILED', 'render_failed', null], [$failed['state'], $failed['error_code'], $failed['artefact']]);

        $done = $this->ready($revision);
        $this->assertSame(['READY', null], [$done['state'], $done['error_code']]);
        $again = $this->ready($revision);
        $this->assertSame($done['artefact']['id'], $again['artefact']['id']);
        $this->assertSame(1, DocumentArtefact::query()->count());
    }

    public function test_every_action_is_default_deny_by_duty_and_by_quotation_visibility(): void
    {
        $revision = $this->issued();
        $this->ready($revision);
        $stranger = User::factory()->create();
        $noView = User::factory()->create();
        $noView->givePermissionTo(array_diff(self::CLERK_DUTIES, [P::QUOTATION_VIEW]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $calls = [
            'status' => fn (User $u) => app(ReadQuotationDocumentStatus::class)($u, $revision),
            'request' => fn (User $u) => app(RequestQuotationDocument::class)($u, $revision, 'k-'.$u->id),
            'download' => fn (User $u) => app(DownloadQuotationDocument::class)($u, $revision),
            'history' => fn (User $u) => app(ReadQuotationDocumentHistory::class)($u, $revision),
            'check' => fn (User $u) => app(RepairQuotationDocument::class)->check($u, $revision),
            'record' => fn (User $u) => app(RecordManualQuotationDelivery::class)($u, $revision, ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'A'], 'k-'.$u->id),
            'deliveries' => fn (User $u) => app(ReadQuotationDocumentDeliveries::class)($u, $revision),
        ];
        foreach ($calls as $name => $call) {
            $this->assertSame('FORBIDDEN', $this->refusal(fn () => $call($stranger)), "{$name}: no duty");
            $this->assertSame('FORBIDDEN', $this->refusal(fn () => $call($noView)), "{$name}: duty without seeing the quotation");
        }
        $this->assertSame('FORBIDDEN', $this->refusal(fn () => app(DownloadQuotationDocument::class)($this->operator, $revision)), 'repair/view do not stand in for download');
        $suspended = User::factory()->create();
        $suspended->givePermissionTo(self::CLERK_DUTIES);
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'SUSPENDED']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame('FORBIDDEN', $this->refusal(fn () => app(DownloadQuotationDocument::class)($suspended, $revision)));
        $this->assertSame(0, DocumentAccessEvent::query()->count(), 'no refused request handed anything over');
    }

    public function test_an_unknown_foreign_or_unissued_revision_is_the_same_not_found(): void
    {
        $draft = $this->preparedDraft();
        foreach ([$draft->id, 999999] as $id) {
            $this->assertSame('DOCUMENT_NOT_FOUND', $this->refusal(fn () => app(ReadQuotationDocumentStatus::class)($this->clerk, $id)));
            $this->assertSame('DOCUMENT_NOT_FOUND', $this->refusal(fn () => app(RequestQuotationDocument::class)($this->clerk, $id, 'k')));
        }
        $issued = $this->issued();
        $this->assertSame('DOCUMENT_NOT_READY', $this->refusal(fn () => app(DownloadQuotationDocument::class)($this->clerk, $issued)));
    }

    public function test_manual_delivery_is_idempotent_corrected_never_edited_and_never_a_receipt(): void
    {
        $revision = $this->issued();
        $artefact = $this->ready($revision)['artefact']['id'];
        $record = app(RecordManualQuotationDelivery::class);
        $fields = ['channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'Mr Customer', 'recipient_detail' => 'front desk', 'note' => 'signed copy kept'];

        $first = $record($this->clerk, $revision, $fields, 'del-1');
        $this->assertFalse($first['replayed']);
        $this->assertSame([$artefact, $this->clerk->id], [$first['delivery']->artefact_id, $first['delivery']->recorded_by]);
        $this->assertTrue($record($this->clerk, $revision, $fields, 'del-1')['replayed']);
        $this->assertSame('DELIVERY_KEY_REUSED', $this->refusal(fn () => $record($this->clerk, $revision, [...$fields, 'recipient_name' => 'Someone else'], 'del-1')));
        $this->assertSame('DELIVERY_INVALID', $this->refusal(fn () => $record($this->clerk, $revision, [...$fields, 'channel' => 'EMAIL'], 'del-2')));
        $this->assertSame('DELIVERY_INVALID', $this->refusal(fn () => $record($this->clerk, $revision, [...$fields, 'delivered_on' => now()->addDays(5)->format('Y-m-d')], 'del-3')));

        $fix = $record($this->clerk, $revision, [...$fields, 'recipient_name' => 'Ms Customer'], 'del-4', $first['delivery']->id);
        $this->assertSame($first['delivery']->id, $fix['delivery']->corrects_id);
        $this->assertSame('DELIVERY_ALREADY_CORRECTED', $this->refusal(fn () => $record($this->clerk, $revision, [...$fields, 'recipient_name' => 'Third'], 'del-5', $first['delivery']->id)));
        $this->assertSame('DELIVERY_NOT_FOUND', $this->refusal(fn () => $record($this->clerk, $revision, $fields, 'del-6', 999999)));
        $this->assertSame([$first['delivery']->id, $fix['delivery']->id], array_map(fn ($d) => $d->id, app(ReadQuotationDocumentDeliveries::class)($this->clerk, $revision)));

        foreach ([fn () => DB::table('quotation_document_deliveries')->update(['note' => 'edited']), fn () => DB::table('quotation_document_deliveries')->delete(),
            fn () => DB::statement('TRUNCATE quotation_document_deliveries')] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, QuotationDocumentDelivery::query()->count());
    }

    public function test_a_delivery_can_only_name_the_original_of_its_own_quotation(): void
    {
        $one = $this->issued();
        $this->ready($one);
        $two = $this->issued();
        $artefactOfTwo = $this->ready($two)['artefact']['id'];
        $this->assertSame(2, DocumentArtefact::query()->count());

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('quotation_document_deliveries')->insert(['company_id' => 1, 'revision_id' => $one, 'artefact_id' => $artefactOfTwo, 'recorded_by' => $this->clerk->id,
            'channel' => 'HAND', 'delivered_on' => $this->today(), 'recipient_name' => 'X', 'operation_key' => 'raw', 'intent_digest' => str_repeat('a', 64), 'recorded_at' => now()]));
    }

    public function test_a_lost_original_is_checked_and_restored_only_from_its_exact_bytes(): void
    {
        $revision = $this->issued();
        $artefact = DocumentArtefact::query()->findOrFail($this->ready($revision)['artefact']['id']);
        $path = StoredObject::path(FileBlob::query()->findOrFail($artefact->blob_id)->storage_key);
        $backup = Storage::disk('files')->get($path);
        Storage::disk('files')->delete($path);

        $this->assertSame('missing', app(RepairQuotationDocument::class)->check($this->operator, $revision));
        $this->assertFalse(app(ReadQuotationDocumentStatus::class)($this->clerk, $revision)['artefact']['available']);
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(DownloadQuotationDocument::class)($this->clerk, $revision)));
        $this->assertNotSame('', $this->refusal(fn () => app(RepairQuotationDocument::class)->restore($this->operator, $revision, $this->stream($backup.'x'), 'wrong copy')));
        app(RepairQuotationDocument::class)->restore($this->operator, $revision, $this->stream($backup), 'restored from the nightly backup');
        $this->assertSame($artefact->sha256, hash('sha256', stream_get_contents(app(DownloadQuotationDocument::class)($this->clerk, $revision)->stream)));
        $this->assertSame(1, DocumentArtefact::query()->count(), 'recovered, never regenerated');
    }
}
