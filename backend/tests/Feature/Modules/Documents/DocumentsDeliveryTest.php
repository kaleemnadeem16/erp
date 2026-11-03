<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\GenerateDocumentArtefact;
use App\Modules\Documents\Actions\ReadDocumentArtefactHistory;
use App\Modules\Documents\Actions\ReconcileDocumentArtefacts;
use App\Modules\Documents\Actions\RestoreDocumentArtefact;
use App\Modules\Documents\Actions\StreamAuthorisedDocument;
use App\Modules\Documents\Models\DocumentAccessEvent;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\TestCase;

/**
 * DF4 — the manual-delivery pilot's shared half: every hand-off of a stored document is recorded (and never mistaken for customer receipt), the reconciliation sweep finds
 * missing and corrupt objects and abandoned generations without touching anything, the owner can read a safe timeline, and a lost object is restored from a backup copy
 * with exactly its recorded hash. Internal only: no route, grant, duty, email, portal or public URL.
 */
class DocumentsDeliveryTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function artefact(string $subject = 'po:1'): DocumentArtefact
    {
        $b = app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', $subject, 1, DocumentManifest::ORIGINAL, 'gen-'.$subject, 'key-'.++$this->keys, $this->user->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return app(GenerateDocumentArtefact::class)->execute($b['intent']->id, $this->user->id)['artefact'];
    }

    private function path(DocumentArtefact $a): string
    {
        return StoredObject::path(FileBlob::query()->findOrFail($a->blob_id)->storage_key);
    }

    private function stream(string $bytes)
    {
        $s = fopen('php://temp', 'r+');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    private function refusal(Closure $call): string
    {
        try {
            $call();
        } catch (DocumentException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_every_hand_off_is_recorded_as_history_and_never_as_receipt(): void
    {
        $a = $this->artefact();
        $dl = app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);
        fclose($dl->stream);
        app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id, 'PRINT');

        $events = DocumentAccessEvent::query()->orderBy('id')->get();
        $this->assertSame([['DOWNLOAD', 'DELIVERED'], ['PRINT', 'DELIVERED']], $events->map(fn ($e) => [$e->access_kind, $e->outcome])->all());
        $this->assertSame([$a->id, $this->user->id], [$events[0]->artefact_id, $events[0]->actor_id]);
        $this->assertSame(['id', 'company_id', 'artefact_id', 'actor_id', 'access_kind', 'outcome', 'occurred_at'], array_keys($events[0]->getAttributes()), 'no hash, key, path or payload is stored');
        $this->assertSame('access_kind_invalid', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id, 'EMAIL')));
        $this->assertSame('access_denied', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, null)));
        $this->assertSame(2, DocumentAccessEvent::query()->count(), 'a refusal before the hand-off records nothing');
    }

    public function test_an_owner_denial_records_nothing_and_an_unavailable_object_records_the_refusal(): void
    {
        $a = $this->artefact();
        $this->adapter->rule = fn (): bool => false;
        $this->assertSame('access_denied', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));
        $this->assertSame(0, DocumentAccessEvent::query()->count());

        $this->adapter->rule = null;
        Storage::disk('files')->put($this->path($a), 'tampered');
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));
        $this->assertSame([['DOWNLOAD', 'UNAVAILABLE']], DocumentAccessEvent::query()->get()->map(fn ($e) => [$e->access_kind, $e->outcome])->all());
    }

    public function test_access_events_are_insert_only_on_the_database_clock_and_tenant_safe(): void
    {
        $a = $this->artefact();
        app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);
        $e = DocumentAccessEvent::query()->firstOrFail();
        $this->assertEqualsWithDelta(0, abs(now()->diffInSeconds($e->occurred_at)), 5);

        foreach ([fn () => DB::table('document_access_events')->update(['outcome' => 'UNAVAILABLE']), fn () => DB::table('document_access_events')->delete(),
            fn () => DB::statement('TRUNCATE document_access_events')] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        // A supplied time is ignored; a foreign company's artefact or a bad kind is refused.
        $id = DB::table('document_access_events')->insertGetId(['company_id' => $a->company_id, 'artefact_id' => $a->id, 'actor_id' => $this->user->id, 'access_kind' => 'PRINT', 'outcome' => 'DELIVERED',
            'occurred_at' => '2000-01-01 00:00:00']);
        $this->assertGreaterThan(2020, (int) Carbon::parse(DB::table('document_access_events')->where('id', $id)->value('occurred_at'))->year);
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['company_id' => $other, 'access_kind' => 'PRINT'], ['company_id' => $a->company_id, 'access_kind' => 'EMAIL']] as $bad) {
            try {
                DB::transaction(fn () => DB::table('document_access_events')->insert(array_merge(['artefact_id' => $a->id, 'actor_id' => $this->user->id, 'outcome' => 'DELIVERED', 'occurred_at' => now()], $bad)));
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_sweep_finds_missing_and_corrupt_objects_in_bounded_resumable_batches_and_changes_nothing_else(): void
    {
        $ok = $this->artefact('po:1');
        $corrupt = $this->artefact('po:2');
        $missing = $this->artefact('po:3');
        Storage::disk('files')->put($this->path($corrupt), 'tampered');
        Storage::disk('files')->delete($this->path($missing));

        $first = app(ReconcileDocumentArtefacts::class)->execute($this->user->id, null, 2);
        $this->assertSame([2, 1, 1, true], [$first['checked'], $first['ok'], $first['corrupt'], is_string($first['next_cursor'])]);
        $second = app(ReconcileDocumentArtefacts::class)->execute($this->user->id, $first['next_cursor'], 2);
        $this->assertSame([1, 1, null], [$second['checked'], $second['missing'], $second['next_cursor']]);
        $this->assertSame(['VERIFIED', 'QUARANTINED', 'MISSING'], [FileBlob::query()->find($ok->blob_id)->health, FileBlob::query()->find($corrupt->blob_id)->health, FileBlob::query()->find($missing->blob_id)->health]);
        $this->assertSame(3, DocumentArtefact::query()->count(), 'nothing is deleted or regenerated');

        // A second pass has nothing left to change.
        $again = app(ReconcileDocumentArtefacts::class)->execute($this->user->id, null, 10);
        $this->assertSame([3, 1, 2], [$again['checked'], $again['ok'], $again['unchanged']]);
        foreach ([[null, 0], [null, 501], ['not-a-cursor', 5]] as [$cursor, $limit]) {
            $this->assertSame('sweep_invalid', $this->refusal(fn () => app(ReconcileDocumentArtefacts::class)->execute($this->user->id, $cursor, $limit)));
        }
    }

    public function test_an_actor_the_owner_refuses_learns_nothing_from_the_sweep_and_nothing_is_examined(): void
    {
        $tampered = $this->artefact('po:1');
        $this->artefact('po:2');
        Storage::disk('files')->put($this->path($tampered), 'tampered');
        $this->adapter->rule = fn (): bool => false;

        $this->assertSame('access_denied', $this->refusal(fn () => app(ReconcileDocumentArtefacts::class)->execute($this->user->id)));
        $this->assertSame('VERIFIED', FileBlob::query()->findOrFail($tampered->blob_id)->health, 'an unauthorised sweep does not even examine the object');
    }

    public function test_inactive_missing_and_foreign_company_actors_are_refused_before_anything_is_computed(): void
    {
        $this->artefact('po:1');
        $suspended = User::factory()->create();
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'SUSPENDED']);
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::factory()->create();
        DB::table('users')->where('id', $foreign->id)->update(['company_id' => $other]);
        $events = DB::table('file_blob_events')->count();

        foreach ([$suspended->id, $foreign->id, 999999] as $actor) {
            $this->assertSame('access_denied', $this->refusal(fn () => app(ReconcileDocumentArtefacts::class)->execute($actor)));
        }
        $this->assertSame($events, DB::table('file_blob_events')->count());
    }

    public function test_only_the_documents_the_actor_may_repair_are_counted_and_no_identity_is_returned(): void
    {
        $allowed = $this->artefact('po:1');
        $blocked = $this->artefact('po:2');
        Storage::disk('files')->delete($this->path($allowed));
        Storage::disk('files')->delete($this->path($blocked));
        $this->adapter->rule = fn (DocumentOperationContext $c): bool => $c->ownerBindingIdentity === 'po:1' || $c->operation !== 'reconcile_artefact';

        $r = app(ReconcileDocumentArtefacts::class)->execute($this->user->id);
        $this->assertSame([1, 1, 0], [$r['checked'], $r['missing'], $r['ok']], 'the blocked document is neither examined nor counted');
        $this->assertSame(['MISSING', 'VERIFIED'], [FileBlob::query()->findOrFail($allowed->blob_id)->health, FileBlob::query()->findOrFail($blocked->blob_id)->health]);
        $this->assertSame(['checked', 'ok', 'missing', 'corrupt', 'unchanged', 'next_cursor', 'orphans'], array_keys($r), 'counts and a sealed cursor only: no blob, file or storage identity');
    }

    public function test_the_cursor_never_reveals_a_hidden_id_and_resuming_scans_everything_the_actor_may_see(): void
    {
        $a = [];
        foreach (range(1, 6) as $n) {
            $a[$n] = $this->artefact('po:'.$n);
        }
        // The caller may repair 1, 3 and 5; 2, 4 and 6 are interleaved across every page boundary and hidden from them.
        $visible = [$a[1]->id, $a[3]->id, $a[5]->id];
        $this->adapter->rule = fn (DocumentOperationContext $c): bool => $c->operation !== 'reconcile_artefact' || in_array($c->ownerBindingIdentity, ['po:1', 'po:3', 'po:5'], true);
        foreach ($a as $n => $artefact) {
            if ($n % 2 === 1) {
                Storage::disk('files')->delete($this->path($artefact));
            }
        }

        $cursor = null;
        $missing = 0;
        $pages = 0;
        do {
            $page = app(ReconcileDocumentArtefacts::class)->execute($this->user->id, $cursor, 2);
            $missing += $page['missing'];
            $cursor = $page['next_cursor'];
            $pages++;
            $text = json_encode($page);
            foreach ([$a[2]->id, $a[4]->id, $a[6]->id] as $hidden) {
                $this->assertDoesNotMatchRegularExpression('/(?<![0-9a-zA-Z=+\/])'.$hidden.'(?![0-9])/', (string) preg_replace('/"next_cursor":"[^"]*"/', '"next_cursor":"x"', $text), 'no hidden id appears in the result');
            }
            $this->assertTrue($cursor === null || (is_string($cursor) && ! ctype_digit($cursor)), 'the cursor is opaque, not an id');
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(count($visible), $missing, 'resuming scanned everything the caller may see');
        $this->assertSame(['MISSING', 'VERIFIED'], [FileBlob::query()->findOrFail($a[5]->blob_id)->health, FileBlob::query()->findOrFail($a[6]->blob_id)->health]);
        // A cursor is bound to its actor and its company, and cannot be forged or edited.
        $other = User::factory()->create();
        $first = app(ReconcileDocumentArtefacts::class)->execute($this->user->id, null, 2)['next_cursor'];
        $this->assertSame('sweep_invalid', $this->refusal(fn () => app(ReconcileDocumentArtefacts::class)->execute($other->id, $first, 2)));
        $this->assertSame('sweep_invalid', $this->refusal(fn () => app(ReconcileDocumentArtefacts::class)->execute($this->user->id, $first.'x', 2)));
    }

    public function test_a_crashed_generation_leaves_a_verified_file_no_artefact_owns_and_the_sweep_reports_it(): void
    {
        $b = app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:9', 1, DocumentManifest::ORIGINAL, 'gen-crash', 'key-crash', $this->user->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        // The object is stored and verified, then the publish crashes: no artefact, no head, the intent is FAILED.
        DocumentArtefact::creating(fn () => throw new RuntimeException('crash while publishing'));
        try {
            app(GenerateDocumentArtefact::class)->execute($b['intent']->id, $this->user->id);
            $this->fail('The crash must surface.');
        } catch (DocumentException $e) {
            $this->assertSame('render_failed', $e->reasonCode);
        } finally {
            DocumentArtefact::getEventDispatcher()->forget('eloquent.creating: '.DocumentArtefact::class);
        }
        $this->assertSame([0, 'FAILED'], [DocumentArtefact::query()->count(), $b['intent']->refresh()->state]);
        $this->assertCount(1, Storage::disk('files')->allFiles());

        $this->assertSame(0, app(ReconcileDocumentArtefacts::class)->execute($this->user->id)['orphans'], 'too recent to be called abandoned');
        Carbon::setTestNow(now()->addHours(3));
        $report = app(ReconcileDocumentArtefacts::class)->execute($this->user->id);
        $this->assertSame(1, $report['orphans']);
        $this->assertCount(1, Storage::disk('files')->allFiles(), 'reported, never deleted');
    }

    public function test_the_history_is_a_safe_ordered_timeline_for_the_owner_only(): void
    {
        $a = $this->artefact();
        app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);
        Storage::disk('files')->delete($this->path($a));
        app(ReconcileDocumentArtefacts::class)->execute($this->user->id);
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id, 'PRINT')));

        $history = app(ReadDocumentArtefactHistory::class)->execute($a->id, $this->user->id);
        $this->assertSame(['GENERATED', 'DOWNLOAD_DELIVERED', 'MISSING_DETECTED', 'PRINT_UNAVAILABLE'], array_column($history, 'event'));
        $blob = FileBlob::query()->findOrFail($a->blob_id);
        $json = json_encode($history);
        foreach ([$blob->storage_key, $a->sha256, 'storage_key', 'sha256', 'path'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('access_denied', $this->refusal(fn () => app(ReadDocumentArtefactHistory::class)->execute($a->id, null)));
        $this->adapter->rule = fn (DocumentOperationContext $c): bool => $c->operation !== 'history';
        $this->assertSame('access_denied', $this->refusal(fn () => app(ReadDocumentArtefactHistory::class)->execute($a->id, $this->user->id)));
        $this->assertSame('history', $this->adapter->asked[array_key_last($this->adapter->asked)]->operation);
    }

    public function test_a_lost_original_is_restored_from_a_backup_copy_with_exactly_its_hash_and_delivery_resumes(): void
    {
        $a = $this->artefact();
        $backup = Storage::disk('files')->get($this->path($a));
        Storage::disk('files')->delete($this->path($a));
        $sweep = app(ReconcileDocumentArtefacts::class)->execute($this->user->id);
        $this->assertSame([1, 1], [$sweep['checked'], $sweep['missing']]);
        $this->assertSame('artefact_unavailable', $this->refusal(fn () => app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id)));

        // Wrong bytes (even a regenerated-looking file) are refused; the exact backup is accepted.
        $this->assertSame('restore_restore_mismatch', $this->refusal(fn () => app(RestoreDocumentArtefact::class)->execute($a->id, $this->stream($backup.' '), 'restore from backup', $this->user->id)));
        $privateReason = 'SYNTHETIC-RESTORE-NOTE private/synthetic-original.pdf';
        app(RestoreDocumentArtefact::class)->execute($a->id, $this->stream($backup), $privateReason, $this->user->id);
        $this->assertSame($backup, Storage::disk('files')->get($this->path($a)));
        $dl = app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);
        $this->assertSame($a->sha256, hash('sha256', stream_get_contents($dl->stream)));
        // A lost response replayed is refused and changes nothing.
        $events = DB::table('file_blob_events')->count();
        $this->assertSame('restore_restore_not_applicable', $this->refusal(fn () => app(RestoreDocumentArtefact::class)->execute($a->id, $this->stream($backup), 'again', $this->user->id)));
        $this->assertSame($events, DB::table('file_blob_events')->count());
        $this->assertSame(['GENERATED', 'MISSING_DETECTED', 'DOWNLOAD_UNAVAILABLE', 'RESTORED', 'DOWNLOAD_DELIVERED'],
            array_column(app(ReadDocumentArtefactHistory::class)->execute($a->id, $this->user->id), 'event'));
        $history = app(ReadDocumentArtefactHistory::class)->execute($a->id, $this->user->id);
        $this->assertStringNotContainsString($privateReason, json_encode($history, JSON_THROW_ON_ERROR));
        $this->assertSame('restored_identical_bytes', collect($history)->firstWhere('event', 'RESTORED')['detail']['reason']);
        $this->assertSame($privateReason, DB::table('file_blob_events')->where('blob_id', $a->blob_id)->where('event', 'RESTORED')->value('reason'), 'redact the projection, never rewrite append-only audit evidence');
    }

    public function test_there_is_still_no_way_in_and_rolling_back_refuses_with_evidence(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Documents/Http'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Documents/routes.php'));
        $a = $this->artefact();
        app(StreamAuthorisedDocument::class)->execute($a->id, $this->user->id);
        $migration = require base_path('database/migrations/2026_10_30_120000_create_document_access_events.php');
        try {
            $migration->down();
            $this->fail('Rolling back with hand-off evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
    }
}
