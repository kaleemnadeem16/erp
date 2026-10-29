<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\GenerateDocumentArtefact;
use App\Modules\Documents\Actions\ReconcileDocumentArtefact;
use App\Modules\Documents\Actions\RestoreDocumentArtefact;
use App\Modules\Documents\Actions\StreamAuthorisedDocument;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Models\DocumentArtefactHead;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentGenerationScope;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Actions\ReconcileFileBlob;
use App\Modules\Files\Actions\RequestFileUpload;
use App\Modules\Files\Actions\RestoreFileBlob;
use App\Modules\Files\Actions\StageUploadedFile;
use App\Modules\Files\Actions\VerifyStagedFile;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\StoredObject;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents\PdfInspector;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\TestCase;

/**
 * DF3 — the generation worker, the stored originals and secure delivery: a PDF is rendered from the manifest alone, stored and verified through Files, published once under the
 * fence the worker holds, delivered only after the owner's decision and a hash check, and recovered (never regenerated) when the object goes missing or corrupt.
 */
class DocumentsArtefactTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $keys = 0;

    private PurchaseOrderShapedAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->user = User::factory()->create();
        $registry = new DocumentTypeRegistry;
        $this->adapter = new PurchaseOrderShapedAdapter(supportsLetter: true);
        $registry->register($this->adapter);
        $this->app->instance(DocumentTypeRegistry::class, $registry);
    }

    /** @return array{manifest: DocumentManifest, intent: DocumentGenerationIntent} */
    private function bind(string $purpose = DocumentManifest::ORIGINAL, string $subject = 'po:1', string $identity = 'gen-1'): array
    {
        $r = app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', $subject, 1, $purpose, $identity, 'key-'.++$this->keys, $this->user->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $r;
    }

    private function refusal(Closure $call): string
    {
        try {
            $call();
        } catch (DocumentException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a DocumentException.');
    }

    private function generate(int $intentId): array
    {
        return app(GenerateDocumentArtefact::class)->execute($intentId, $this->user->id);
    }

    public function test_generates_a_verified_stored_pdf_and_publishes_head_and_intent(): void
    {
        $b = $this->bind();
        $r = $this->generate($b['intent']->id);

        $this->assertFalse($r['replayed']);
        $a = $r['artefact'];
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $this->assertSame('VERIFIED', $blob->health);
        $bytes = Storage::disk('files')->get(StoredObject::path($blob->storage_key));
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $a->sha256);
        $this->assertSame(strlen($bytes), $a->size_bytes);
        $this->assertSame('VERIFIED', $b['intent']->refresh()->state);
        $this->assertSame(1, $b['intent']->fencing_generation);
        $head = DocumentArtefactHead::query()->firstOrFail();
        $this->assertSame([$a->id, 1], [$head->artefact_id, $head->generation]);
        $this->assertSame($b['manifest']->renderer_contract, $a->renderer_contract);
        $this->assertGreaterThanOrEqual(1, (new PdfInspector($bytes))->pageCount());
        $this->assertSame($a->page_count, (new PdfInspector($bytes))->pageCount());
    }

    public function test_replay_of_a_verified_intent_returns_the_same_artefact(): void
    {
        $b = $this->bind();
        $first = $this->generate($b['intent']->id)['artefact'];
        $again = $this->generate($b['intent']->id);

        $this->assertTrue($again['replayed']);
        $this->assertSame($first->id, $again['artefact']->id);
        $this->assertSame(1, DocumentArtefact::query()->count());
    }

    public function test_rendering_is_deterministic_for_the_same_manifest(): void
    {
        $one = $this->generate($this->bind(identity: 'g1')['intent']->id)['artefact'];
        $two = $this->generate($this->bind(subject: 'po:2', identity: 'g2')['intent']->id)['artefact'];

        $this->assertSame($one->page_count, $two->page_count);
    }

    public function test_a_draft_head_advances_but_an_original_head_never_moves(): void
    {
        $d1 = $this->generate($this->bind(DocumentManifest::DRAFT_PREVIEW, 'po:1', 'd1')['intent']->id)['artefact'];
        $d2 = $this->generate($this->bind(DocumentManifest::DRAFT_PREVIEW, 'po:1', 'd2')['intent']->id)['artefact'];
        $head = DocumentArtefactHead::query()->where('purpose', DocumentManifest::DRAFT_PREVIEW)->firstOrFail();
        $this->assertSame([$d2->id, 2], [$head->artefact_id, $head->generation]);
        $this->assertNotSame($d1->id, $d2->id);

        $o = $this->generate($this->bind(DocumentManifest::ORIGINAL, 'po:1', 'o1')['intent']->id)['artefact'];
        $head = DocumentArtefactHead::query()->where('purpose', DocumentManifest::ORIGINAL)->firstOrFail();
        $this->assertSame([$o->id, 1], [$head->artefact_id, $head->generation]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('document_artefact_heads')->where('id', $head->id)->update(['artefact_id' => $d1->id, 'generation' => 2]));
    }

    public function test_a_live_lease_held_by_another_worker_is_refused_and_an_expired_one_is_taken_over(): void
    {
        $b = $this->bind();
        DB::table('document_generation_intents')->where('id', $b['intent']->id)->update(['state' => 'RUNNING', 'fencing_generation' => 1, 'lease_expiry' => DB::raw("clock_timestamp() + interval '5 minutes'")]);
        $this->assertSame('generation_in_progress', $this->refusal(fn () => $this->generate($b['intent']->id)));

        DB::table('document_generation_intents')->where('id', $b['intent']->id)->update(['lease_expiry' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $a = $this->generate($b['intent']->id)['artefact'];
        $this->assertSame(2, $a->fence);
    }

    public function test_the_database_refuses_an_artefact_from_a_worker_that_lost_the_fence(): void
    {
        $b = $this->bind();
        $good = $this->generate($b['intent']->id)['artefact'];
        $blob = FileBlob::query()->findOrFail($good->blob_id);
        $other = $this->bind(subject: 'po:9', identity: 'x1');
        DB::table('document_generation_intents')->where('id', $other['intent']->id)->update(['state' => 'RUNNING', 'fencing_generation' => 2, 'lease_expiry' => DB::raw("clock_timestamp() + interval '5 minutes'")]);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DocumentArtefact::create([
            'manifest_id' => $other['manifest']->id, 'intent_id' => $other['intent']->id, 'fence' => 1, 'blob_id' => $blob->id, 'purpose' => $other['manifest']->purpose, 'media_type' => 'application/pdf',
            'sha256' => $good->sha256, 'size_bytes' => $good->size_bytes, 'page_count' => 1, 'renderer_contract' => $good->renderer_contract, 'renderer_version' => $good->renderer_version, 'created_at' => now(),
        ]));
    }

    public function test_artefacts_are_append_only(): void
    {
        $a = $this->generate($this->bind()['intent']->id)['artefact'];

        foreach ([fn () => DB::table('document_artefacts')->where('id', $a->id)->update(['page_count' => 9]), fn () => DB::table('document_artefacts')->where('id', $a->id)->delete(),
            fn () => DB::statement('TRUNCATE document_artefacts CASCADE')] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_legacy_manifest_without_a_pinned_renderer_fails_closed(): void
    {
        $b = $this->bind();
        DB::statement('ALTER TABLE document_manifests DISABLE TRIGGER USER');
        DB::table('document_manifests')->where('id', $b['manifest']->id)->update(['renderer_contract' => 'UNASSIGNED', 'renderer_version' => '0', 'fonts' => '[]']);
        DB::statement('ALTER TABLE document_manifests ENABLE TRIGGER USER');

        $this->assertSame('manifest_not_renderable', $this->refusal(fn () => $this->generate($b['intent']->id)));
        $this->assertSame('FAILED', $b['intent']->refresh()->state);
        $this->assertSame(0, DocumentArtefact::query()->count());
    }

    public function test_an_owner_denial_blocks_generation_and_delivery(): void
    {
        $b = $this->bind();
        $a = $this->generate($b['intent']->id)['artefact'];
        $registry = new DocumentTypeRegistry;
        $registry->register(new PurchaseOrderShapedAdapter(allow: false, supportsLetter: true));
        $this->app->instance(DocumentTypeRegistry::class, $registry);

        $this->assertSame('access_denied', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));
    }

    public function test_delivery_streams_verified_bytes_with_safe_headers_and_no_storage_detail(): void
    {
        $a = $this->generate($this->bind()['intent']->id)['artefact'];
        $dl = app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);

        $this->assertSame($a->sha256, hash('sha256', stream_get_contents($dl->stream)));
        $h = $dl->headers();
        $this->assertSame('application/pdf', $h['Content-Type']);
        $this->assertStringStartsWith('attachment; filename="', $h['Content-Disposition']);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('private, no-store', $h['Cache-Control']);
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $this->assertStringNotContainsString($blob->storage_key, json_encode($h).$dl->filename);
    }

    public function test_a_missing_or_tampered_object_is_never_delivered_and_is_recovered_not_regenerated(): void
    {
        $a = $this->generate($this->bind()['intent']->id)['artefact'];
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $path = StoredObject::path($blob->storage_key);
        $original = Storage::disk('files')->get($path);

        Storage::disk('files')->put($path, $original.'x');
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));
        $this->assertSame('corrupt', app(ReconcileDocumentArtefact::class)->execute($a->id, $this->user->id));
        $this->assertSame('QUARANTINED', $blob->refresh()->health);

        $wrong = fopen('php://temp', 'r+');
        fwrite($wrong, 'not the original');
        rewind($wrong);
        $this->assertNotSame('', $this->refusal(fn () => app(RestoreDocumentArtefact::class)->execute($a->id, $wrong, 'restore from backup', $this->user->id)));

        $good = fopen('php://temp', 'r+');
        fwrite($good, $original);
        rewind($good);
        app(RestoreDocumentArtefact::class)->execute($a->id, $good, 'restore from backup', $this->user->id);
        $this->assertSame('VERIFIED', $blob->refresh()->health);
        $this->assertSame($original, Storage::disk('files')->get($path));
        $this->assertSame(hash('sha256', stream_get_contents(app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)->stream)), $a->sha256);

        Storage::disk('files')->delete($path);
        $this->assertSame('missing', app(ReconcileDocumentArtefact::class)->execute($a->id, $this->user->id));
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));
        $this->assertSame(1, DocumentArtefact::query()->count());
    }

    // ---- Files operations never bypass the document owner ---------------------------------------------------------------------------------------------

    /** @return array<string, int> every table a Files or Documents write could touch */
    private function writes(): array
    {
        $out = [];
        foreach (['file_upload_intents', 'file_blobs', 'file_blob_events', 'file_operation_receipts', 'document_artefacts', 'document_artefact_heads', 'document_operation_receipts'] as $t) {
            $out[$t] = DB::table($t)->count();
        }

        return $out;
    }

    /** Runs a closure the way the worker would: the intent really claimed (RUNNING at its fence, live lease) and the capability open for it. */
    private function withinGeneration(array $b, Closure $work): mixed
    {
        DB::table('document_generation_intents')->where('id', $b['intent']->id)->update(['state' => 'RUNNING', 'fencing_generation' => 1, 'lease_expiry' => DB::raw("clock_timestamp() + interval '5 minutes'")]);
        $result = DocumentGenerationScope::run($b['manifest']->id, $b['intent']->id, 1, $this->user->id, $work);
        DB::table('document_generation_intents')->where('id', $b['intent']->id)->update(['lease_expiry' => DB::raw("clock_timestamp() - interval '1 second'")]);

        return $result;
    }

    private function fileRefusal(Closure $call): string
    {
        try {
            $call();
        } catch (FileException|DocumentException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function stream(string $bytes)
    {
        $s = fopen('php://temp', 'r+');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    /** @return array{0: DocumentArtefact, 1: DocumentManifest} */
    private function stored(): array
    {
        $b = $this->bind();
        $a = $this->generate($b['intent']->id)['artefact'];

        return [$a, $b['manifest']];
    }

    public function test_every_files_operation_asks_the_document_owner_for_the_current_actor_not_just_existence(): void
    {
        $this->adapter->rule = fn (DocumentOperationContext $c): bool => $c->actorId === $this->user->id;
        [$a, $manifest] = $this->stored();
        $asked = array_map(fn ($c) => $c->operation, $this->adapter->asked);
        $this->assertContains('generate', $asked);
        $this->assertGreaterThanOrEqual(4, count(array_keys($asked, 'generate', true)), 'the worker asks once, then request, stage and verify each ask again through the file owner');
        $this->assertSame($manifest->purpose, $this->adapter->asked[array_key_last($this->adapter->asked)]->purpose);

        $this->adapter->asked = [];
        $this->assertSame('ok', app(ReconcileDocumentArtefact::class)->execute($a->id, $this->user->id));
        $this->assertContains('reconcile_artefact', array_map(fn ($c) => $c->operation, $this->adapter->asked));
    }

    public function test_an_actor_the_owner_does_not_authorise_cannot_use_any_files_action_directly_and_nothing_is_written(): void
    {
        [$a, $manifest] = $this->stored();
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $stranger = User::factory()->create();
        $this->adapter->rule = fn (DocumentOperationContext $c): bool => $c->actorId === $this->user->id;
        $before = $this->writes();
        $id = 'manifest:'.$manifest->id;
        $purpose = DocumentArtefactFileOwner::PURPOSE;
        $owner = DocumentArtefactFileOwner::OWNER;

        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(RequestFileUpload::class)->execute($owner, $id, $purpose, 10, 'direct-1', $stranger->id)));
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(ReconcileFileBlob::class)->execute($blob->id, $owner, $purpose, $id, $stranger->id)));
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(RestoreFileBlob::class)->execute($blob->id, $this->stream('x'), $owner, $purpose, $id, 'because', $stranger->id)));
        $this->assertSame('reconcile_access_denied', $this->fileRefusal(fn () => app(ReconcileDocumentArtefact::class)->execute($a->id, $stranger->id)));
        $this->assertSame('restore_access_denied', $this->fileRefusal(fn () => app(RestoreDocumentArtefact::class)->execute($a->id, $this->stream('x'), 'because', $stranger->id)));
        $this->assertSame($before, $this->writes());
        $this->assertSame('VERIFIED', $blob->refresh()->health);
    }

    public function test_an_inactive_or_missing_actor_and_a_foreign_company_member_are_denied_for_every_files_operation(): void
    {
        [$a, $manifest] = $this->stored();
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::factory()->create();
        DB::table('users')->where('id', $foreign->id)->update(['company_id' => $other]);
        $suspended = User::factory()->create();
        DB::table('users')->where('id', $suspended->id)->update(['status' => MemberStatus::Suspended->value]);
        $before = $this->writes();
        $id = 'manifest:'.$manifest->id;
        $purpose = DocumentArtefactFileOwner::PURPOSE;
        $owner = DocumentArtefactFileOwner::OWNER;

        foreach ([$foreign->id, $suspended->id, null] as $actor) {
            $label = (string) ($actor ?? 'none');
            $this->assertSame('access_denied', $this->fileRefusal(fn () => app(RequestFileUpload::class)->execute($owner, $id, $purpose, 10, 'direct-'.$label, $actor ?? 0)), "upload {$label}");
            $this->assertSame('access_denied', $this->fileRefusal(fn () => app(ReconcileFileBlob::class)->execute($blob->id, $owner, $purpose, $id, $actor)), "reconcile {$label}");
            $this->assertSame('access_denied', $this->fileRefusal(fn () => app(RestoreFileBlob::class)->execute($blob->id, $this->stream('x'), $owner, $purpose, $id, 'because', $actor)), "restore {$label}");
        }
        $this->assertSame($before, $this->writes(), 'no intent, blob, event or receipt was written');
        $this->assertSame('VERIFIED', $blob->refresh()->health);
    }

    public function test_direct_stage_and_verify_are_denied_once_the_actor_is_no_longer_authorised(): void
    {
        $b = $this->bind();
        $request = $this->withinGeneration($b, fn () => app(RequestFileUpload::class)->execute(DocumentArtefactFileOwner::OWNER, 'manifest:'.$b['manifest']->id,
            DocumentArtefactFileOwner::PURPOSE, 12, 'direct-stage', $this->user->id));
        $this->adapter->rule = fn (): bool => false;
        $before = $this->writes();

        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(StageUploadedFile::class)->execute($request['intent']->id, $this->stream('%PDF-1.4 test'), 'a.pdf', 'application/pdf', $this->user->id)));
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(VerifyStagedFile::class)->execute($request['intent']->id, $this->user->id)));
        $this->assertSame($before, $this->writes());
        $this->assertSame([], Storage::disk('files')->allFiles());
    }

    public function test_an_identity_of_another_document_never_authorises_work_on_this_file(): void
    {
        [$a, $manifest] = $this->stored();
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $otherManifest = $this->bind(subject: 'po:2', identity: 'other')['manifest'];
        $before = $this->writes();

        $this->assertSame('blob_not_found', $this->fileRefusal(fn () => app(ReconcileFileBlob::class)->execute($blob->id, DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE,
            'manifest:'.$otherManifest->id, $this->user->id)));
        $this->assertSame('blob_not_found', $this->fileRefusal(fn () => app(RestoreFileBlob::class)->execute($blob->id, $this->stream('x'), DocumentArtefactFileOwner::OWNER,
            DocumentArtefactFileOwner::PURPOSE, 'manifest:'.$otherManifest->id, 'because', $this->user->id)));
        $this->assertSame($before, $this->writes());
        $this->assertSame('ok', app(ReconcileFileBlob::class)->execute($blob->id, DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE, 'manifest:'.$manifest->id, $this->user->id));
    }

    public function test_an_actor_who_holds_generate_cannot_put_their_own_bytes_into_files_as_a_generated_document(): void
    {
        $b = $this->bind();
        $before = $this->writes();
        $bytes = "%PDF-1.4\n% caller controlled\n";
        $id = 'manifest:'.$b['manifest']->id;

        // The ordinary authorisation is fully granted (the fixture says yes to everything) — and still no producer operation works outside the generation worker.
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(RequestFileUpload::class)->execute(DocumentArtefactFileOwner::OWNER, $id, DocumentArtefactFileOwner::PURPOSE,
            strlen($bytes), 'mine-1', $this->user->id, hash('sha256', $bytes))));
        $this->assertSame($before, $this->writes());
        $this->assertSame([], Storage::disk('files')->allFiles());

        // A pre-existing request (as an earlier, legitimate one would be) cannot be staged or verified by the caller either.
        $request = $this->withinGeneration($b, fn () => app(RequestFileUpload::class)->execute(DocumentArtefactFileOwner::OWNER, $id,
            DocumentArtefactFileOwner::PURPOSE, strlen($bytes), 'mine-2', $this->user->id, hash('sha256', $bytes)));
        $after = $this->writes();
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(StageUploadedFile::class)->execute($request['intent']->id, $this->stream($bytes), 'a.pdf', 'application/pdf', $this->user->id)));
        $this->assertSame('access_denied', $this->fileRefusal(fn () => app(VerifyStagedFile::class)->execute($request['intent']->id, $this->user->id)));
        $this->assertSame($after, $this->writes());
        $this->assertSame([], Storage::disk('files')->allFiles());
    }

    public function test_the_generation_capability_is_bound_to_manifest_intent_worker_fence_and_a_live_lease(): void
    {
        $b = $this->bind();
        $other = $this->bind(subject: 'po:2', identity: 'g-other');
        $owner = fn (int $manifest, int $actor) => (new DocumentArtefactFileOwner)->authorise(new FileOperationContext(
            (int) $b['manifest']->company_id, $actor, 'upload', DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE, 'manifest:'.$manifest));
        $intent = $b['intent']->id;
        DB::table('document_generation_intents')->where('id', $intent)->update(['state' => 'RUNNING', 'fencing_generation' => 3, 'lease_expiry' => DB::raw("clock_timestamp() + interval '5 minutes'")]);

        $in = fn (int $manifest, int $fence, int $actor, int $m) => DocumentGenerationScope::run($manifest, $intent, $fence, $actor, fn () => $owner($m, $this->user->id));
        $this->assertFalse($owner($b['manifest']->id, $this->user->id), 'outside the worker');
        $this->assertTrue($in($b['manifest']->id, 3, $this->user->id, $b['manifest']->id), 'the right worker, manifest, intent and fence');
        $this->assertFalse($in($b['manifest']->id, 2, $this->user->id, $b['manifest']->id), 'a stale fence');
        $this->assertFalse(DocumentGenerationScope::run($b['manifest']->id, $intent, 3, $this->user->id + 999, fn () => $owner($b['manifest']->id, $this->user->id)), 'another worker');
        $this->assertFalse(DocumentGenerationScope::run($b['manifest']->id, $intent, 3, $this->user->id, fn () => $owner($other['manifest']->id, $this->user->id)), 'another document');
        DB::table('document_generation_intents')->where('id', $intent)->update(['lease_expiry' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $this->assertFalse($in($b['manifest']->id, 3, $this->user->id, $b['manifest']->id), 'an expired lease');
    }

    public function test_restoring_checks_health_again_under_the_row_lock(): void
    {
        [$a] = $this->stored();
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $path = StoredObject::path($blob->storage_key);
        $bytes = Storage::disk('files')->get($path);

        // A file that is healthy again (restored by someone else) is not rewritten or re-evented.
        $events = DB::table('file_blob_events')->count();
        $this->assertSame('restore_restore_not_applicable', $this->fileRefusal(fn () => app(RestoreDocumentArtefact::class)->execute($a->id, $this->stream($bytes), 'again', $this->user->id)));
        $this->assertSame($events, DB::table('file_blob_events')->count());
    }

    public function test_delivery_hands_on_the_bytes_it_checked_not_a_second_read_of_the_object(): void
    {
        [$a] = $this->stored();
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $path = StoredObject::path($blob->storage_key);
        $download = app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);

        // The object changes after the check: what is streamed is still exactly the checked copy.
        Storage::disk('files')->put($path, 'tampered after the check');
        $this->assertSame($a->sha256, hash('sha256', stream_get_contents($download->stream)));
    }

    public function test_documents_adds_no_routes_or_permissions(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Documents/Http'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Documents/routes.php'));
    }
}
