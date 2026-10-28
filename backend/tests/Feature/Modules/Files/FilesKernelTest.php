<?php

namespace Tests\Feature\Modules\Files;

use App\Models\User;
use App\Modules\Files\Actions\RequestFileUpload;
use App\Modules\Files\Actions\StageUploadedFile;
use App\Modules\Files\Actions\VerifyStagedFile;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Models\FileBlobEvent;
use App\Modules\Files\Models\FileOperationReceipt;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\FilePurpose;
use App\Modules\Files\Support\FileScanner;
use App\Modules\Files\Support\FileValidator;
use App\Modules\Files\Support\NotConfiguredScanner;
use App\Modules\Files\Support\ScanResult;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files\TestFiles;
use Tests\Support\Files\TestOwnerAdapter;
use Tests\TestCase;

/**
 * Files F0 — the disabled storage kernel over the real database and a fake private disk: requesting an upload, staging validated bytes under an opaque key, verifying
 * them, every refusal the kernel must make (types, names, sizes, images, hashes, owners, expiry), fail-closed owner access with content-free logs, and no way in.
 */
class FilesKernelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TestOwnerAdapter $owner;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->user = User::factory()->create();
        $this->useOwner(new TestOwnerAdapter);
    }

    private function useOwner(TestOwnerAdapter $owner): void
    {
        $this->owner = $owner;
        $registry = new FileOwnerRegistry;
        $registry->register($owner);
        $this->app->instance(FileOwnerRegistry::class, $registry);
    }

    private function request(string $purpose = TestOwnerAdapter::LOGO, ?int $size = null, ?string $key = null, ?string $sha = null, string $identity = 'company:1'): array
    {
        return app(RequestFileUpload::class)->execute(TestOwnerAdapter::KEY, $identity, $purpose, $size ?? strlen(TestFiles::png()), $key ?? 'upload-'.++$this->keys, $this->user->id, $sha);
    }

    private function stage(FileUploadIntent $intent, string $bytes, ?string $name = 'logo.png', ?string $type = 'image/png'): array
    {
        return app(StageUploadedFile::class)->execute($intent->id, TestFiles::stream($bytes), $name, $type, $this->user->id);
    }

    private function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (FileException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a FileException.');
    }

    private function counts(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['file_blobs', 'file_blob_events', 'file_upload_intents', 'file_operation_receipts']);
    }

    private function objects(): array
    {
        return Storage::disk('files')->allFiles();
    }

    // ---- requesting ----------------------------------------------------------------------------------------------------------------------------------

    public function test_a_request_records_a_pending_intent_idempotently(): void
    {
        $first = $this->request(key: 'same');
        $this->assertFalse($first['replayed']);
        $intent = $first['intent'];
        $this->assertSame(['PENDING', null, TestOwnerAdapter::KEY, TestOwnerAdapter::LOGO, $this->user->id], [$intent->state, $intent->blob_id, $intent->owner_type_key, $intent->purpose_key, $intent->actor_id]);
        $this->assertTrue($intent->expires_at->isFuture());
        $this->assertSame([0, 0, 1, 1], $this->counts());

        $again = $this->request(key: 'same');
        $this->assertTrue($again['replayed']);
        $this->assertSame($intent->id, $again['intent']->id);
        $this->assertSame([0, 0, 1, 1], $this->counts());
        $this->assertSame('key_conflict', $this->refusal(fn () => $this->request(TestOwnerAdapter::DOCUMENT, 100, 'same')));
        $this->assertSame('key_conflict', $this->refusal(fn () => $this->request(key: 'same', sha: str_repeat('a', 64))));
        $this->assertSame([0, 0, 1, 1], $this->counts());
        $this->assertSame([], $this->objects(), 'nothing is stored by a request');
    }

    public function test_requests_that_cannot_be_served_refuse_before_anything_is_written(): void
    {
        $this->assertSame('size_exceeded', $this->refusal(fn () => $this->request(size: 2_097_153)));
        $this->assertSame('purpose_unknown', $this->refusal(fn () => $this->request('testing.nope')));
        $this->assertSame('owner_missing', $this->refusal(fn () => app(RequestFileUpload::class)->execute('nobody.home', 'x', 'y.z', 10, 'k', $this->user->id)));
        foreach ([['  ', 'k', 10, null], ['company:1', '', 10, null], ['company:1', 'k', 0, null], ['company:1', 'k', 10, 'NOT-A-HASH'], ['company:1', str_repeat('k', 121), 10, null]] as [$identity, $key, $size, $sha]) {
            $this->assertSame('request_invalid', $this->refusal(fn () => app(RequestFileUpload::class)->execute(TestOwnerAdapter::KEY, $identity, TestOwnerAdapter::LOGO, $size, $key, $this->user->id, $sha)));
        }
        $this->assertSame([0, 0, 0, 0], $this->counts());
    }

    public function test_denied_or_throwing_owner_access_fails_closed_and_the_log_carries_no_content(): void
    {
        Log::spy();
        $this->useOwner(new TestOwnerAdapter(allow: false));
        $this->assertSame('access_denied', $this->refusal(fn () => $this->request(identity: 'secret-subject-77')));
        $this->useOwner(new TestOwnerAdapter(throws: true));
        $this->assertSame('access_denied', $this->refusal(fn () => $this->request(identity: 'secret-subject-77')));
        $this->assertSame([0, 0, 0, 0], $this->counts());

        Log::shouldHaveReceived('warning')->with('files.access_refused', \Mockery::on(fn (array $c) => $c['outcome'] === 'denied' && $c['operation'] === 'upload' && $c['owner_type_key'] === TestOwnerAdapter::KEY
            && $c['purpose_key'] === TestOwnerAdapter::LOGO && $c['company_id'] === 1 && $c['actor_id'] === $this->user->id))->once();
        Log::shouldHaveReceived('warning')->with('files.access_refused', \Mockery::on(fn (array $c) => $c['outcome'] === 'threw' && isset($c['exception'])))->once();
        Log::shouldHaveReceived('warning')->with('files.access_refused', \Mockery::on(fn (array $c) => ! str_contains((string) json_encode($c), 'secret')))->twice();
    }

    public function test_purposes_can_never_list_active_or_executable_content(): void
    {
        foreach (['image/svg+xml', 'text/html', 'application/zip', 'application/javascript', 'application/x-dosexec', 'application/xml', 'text/x-php', 'application/gzip'] as $type) {
            try {
                new FilePurpose('testing.bad', [$type => ['dat']], 100);
                $this->fail("{$type} must be refused.");
            } catch (FileException $e) {
                $this->assertSame('media_type_forbidden', $e->reasonCode, $type);
            }
        }
        foreach (['svg', 'html', 'php', 'exe', 'js', 'zip', 'sh', 'jar'] as $extension) {
            try {
                new FilePurpose('testing.bad', ['image/png' => [$extension]], 100);
                $this->fail("{$extension} must be refused.");
            } catch (FileException $e) {
                $this->assertSame('extension_forbidden', $e->reasonCode, $extension);
            }
        }
        $this->expectException(FileException::class);
        new FilePurpose('Bad Key', ['image/png' => ['png']], 100);
    }

    // ---- staging -------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_valid_image_is_staged_under_an_opaque_key_with_its_true_hash_and_evidence(): void
    {
        $bytes = TestFiles::png();
        $intent = $this->request()['intent'];
        $result = $this->stage($intent, $bytes, 'C:\\evil\\..\\Logo.PNG');
        $blob = $result['blob'];

        $this->assertFalse($result['replayed']);
        $this->assertSame(['STAGED', 'NOT_CONFIGURED', 'files', 'image/png', 'image/png', strlen($bytes), hash('sha256', $bytes), $this->user->id], [$blob->health, $blob->scan_state, $blob->storage_disk,
            $blob->detected_media_type, $blob->declared_media_type, $blob->size_bytes, $blob->sha256, $blob->created_by]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $blob->storage_key);
        $this->assertStringNotContainsString(substr($blob->sha256, 0, 8), $blob->storage_key, 'the key is never derived from the content');
        $this->assertStringNotContainsStringIgnoringCase('logo', $blob->storage_key, 'nor from the name');
        $path = substr($blob->storage_key, 0, 2).'/'.$blob->storage_key;
        $this->assertSame([$path], $this->objects());
        $this->assertSame($bytes, Storage::disk('files')->get($path));
        $this->assertSame('STAGED', $result['intent']->state);
        $this->assertSame($blob->id, $result['intent']->blob_id);

        $event = FileBlobEvent::query()->sole();
        $this->assertSame(['STAGED', null, 'STAGED', $blob->sha256, $this->user->id], [$event->event, $event->from_health, $event->to_health, $event->observed_sha256, $event->actor_id]);
        $this->assertSame(['size' => strlen($bytes), 'media_type' => 'image/png'], $event->evidence, 'the evidence carries no name, key or path');
        $this->assertSame(['stage_upload', 'stage:'.$intent->id], [FileOperationReceipt::query()->where('operation', 'stage_upload')->value('operation'), FileOperationReceipt::query()->where('operation', 'stage_upload')->value('operation_key')]);
        $this->assertSame('image/jpeg', $this->stage($this->request(size: strlen(TestFiles::jpeg()))['intent'], TestFiles::jpeg(), 'photo.jpg', 'image/jpeg')['blob']->detected_media_type);
    }

    public function test_content_the_purpose_does_not_accept_is_refused_and_recorded_as_a_rejected_blob_with_no_object(): void
    {
        $cases = [
            'svg' => [TestFiles::svg(), 'logo.png', 'media_type_not_allowed'],
            'html' => [TestFiles::html(), 'logo.png', 'media_type_not_allowed'],
            'php' => [TestFiles::php(), 'logo.png', 'media_type_not_allowed'],
            'zip' => [TestFiles::zip(), 'logo.png', 'media_type_not_allowed'],
            'executable' => [TestFiles::elf(), 'logo.png', 'media_type_not_allowed'],
            'pdf for a logo' => [TestFiles::pdf(), 'logo.png', 'media_type_not_allowed'],
            'jpeg named png' => [TestFiles::jpeg(), 'logo.png', 'extension_mismatch'],
            'png named jpg' => [TestFiles::png(), 'logo.jpg', 'extension_mismatch'],
            'double extension' => [TestFiles::png(), 'logo.php.png', 'extension_forbidden'],
            'svg extension' => [TestFiles::png(), 'logo.svg.png', 'extension_forbidden'],
            'no extension' => [TestFiles::png(), 'logo', 'extension_mismatch'],
            'dotfile' => [TestFiles::png(), '.png', 'extension_mismatch'],
            'undecodable image' => [substr(TestFiles::png(), 0, 20), 'logo.png', 'image_invalid'],
        ];
        foreach ($cases as $label => [$bytes, $name, $reason]) {
            $intent = $this->request(size: strlen($bytes))['intent'];
            $this->assertSame($reason, $this->refusal(fn () => $this->stage($intent, $bytes, $name, null)), $label);
            $intent->refresh();
            $this->assertSame(['ABORTED', $reason], [$intent->state, $intent->error_code], $label);
            $blob = FileBlob::query()->findOrFail($intent->blob_id);
            $this->assertSame(['REJECTED', hash('sha256', $bytes), strlen($bytes)], [$blob->health, $blob->sha256, $blob->size_bytes], $label);
            $this->assertSame($reason, FileBlobEvent::query()->where('blob_id', $blob->id)->sole()->reason, $label);
        }
        $this->assertSame([], $this->objects(), 'no refused byte was ever stored');
        // A declared type that disagrees with the bytes is refused too, and a closed intent is not reopened.
        $intent = $this->request()['intent'];
        $this->assertSame('declared_type_mismatch', $this->refusal(fn () => $this->stage($intent, TestFiles::png(), 'logo.png', 'image/jpeg')));
        $this->assertSame('intent_closed', $this->refusal(fn () => $this->stage($intent->refresh(), TestFiles::png())));
    }

    public function test_size_ceiling_empty_files_and_announced_size_or_hash_are_enforced(): void
    {
        // Over the ceiling while streaming: the intent aborts, no blob, no object. The request announced a small size, the bytes are larger than the purpose accepts.
        $this->useOwner(new TestOwnerAdapter(logoMaxBytes: 100));
        $intent = $this->request(size: 50)['intent'];
        $this->assertSame('size_exceeded', $this->refusal(fn () => $this->stage($intent, str_repeat('x', 101))));
        $this->assertSame(['ABORTED', 'size_exceeded', null], [$intent->refresh()->state, $intent->error_code, $intent->blob_id]);
        $empty = $this->request(size: 10)['intent'];
        $this->assertSame('file_empty', $this->refusal(fn () => $this->stage($empty, '')));
        $this->assertSame('ABORTED', $empty->refresh()->state);
        $this->assertSame([0, 0], [DB::table('file_blobs')->count(), count($this->objects())]);

        $this->useOwner(new TestOwnerAdapter);
        $png = TestFiles::png();
        $wrongSize = $this->request(size: strlen($png) + 1)['intent'];
        $this->assertSame('size_mismatch', $this->refusal(fn () => $this->stage($wrongSize, $png)));
        $wrongHash = $this->request(sha: str_repeat('a', 64))['intent'];
        $this->assertSame('hash_mismatch', $this->refusal(fn () => $this->stage($wrongHash, $png)));
        $rightHash = $this->request(sha: hash('sha256', $png))['intent'];
        $this->assertSame('STAGED', $this->stage($rightHash, $png)['blob']->health);
        $this->assertCount(1, $this->objects());
    }

    public function test_an_image_over_the_pixel_ceiling_is_refused(): void
    {
        $this->useOwner(new TestOwnerAdapter(logoMaxPixels: 10_000));
        $big = TestFiles::pngWithDimensions(200, 200);
        $intent = $this->request(size: strlen($big))['intent'];
        $this->assertSame('image_too_large', $this->refusal(fn () => $this->stage($intent, $big)));
        $ok = $this->request()['intent'];
        $this->assertSame('STAGED', $this->stage($ok, TestFiles::png())['blob']->health);
    }

    public function test_an_expired_closed_or_unknown_request_cannot_be_staged(): void
    {
        $intent = $this->request()['intent'];
        DB::statement('SET session_replication_role = replica');
        DB::table('file_upload_intents')->where('id', $intent->id)->update(['expires_at' => now()->subMinute()]);
        DB::statement('SET session_replication_role = DEFAULT');
        $this->assertSame('intent_expired', $this->refusal(fn () => $this->stage($intent->refresh(), TestFiles::png())));
        $this->assertSame('EXPIRED', $intent->refresh()->state);
        $this->assertSame('intent_closed', $this->refusal(fn () => $this->stage($intent, TestFiles::png())));
        $this->assertSame('intent_not_found', $this->refusal(fn () => app(StageUploadedFile::class)->execute(9999999, TestFiles::stream('x'))));
        $this->assertSame([], $this->objects());
    }

    public function test_the_owner_is_asked_again_at_staging_and_verifying(): void
    {
        $intent = $this->request()['intent'];
        $this->useOwner(new TestOwnerAdapter(allow: false));
        $this->assertSame('access_denied', $this->refusal(fn () => $this->stage($intent, TestFiles::png())));
        $this->assertSame('PENDING', $intent->refresh()->state);
        $this->assertSame([], $this->objects());
        $this->useOwner(new TestOwnerAdapter);
        $staged = $this->stage($intent, TestFiles::png());
        $this->useOwner(new TestOwnerAdapter(throws: true));
        $this->assertSame('access_denied', $this->refusal(fn () => app(VerifyStagedFile::class)->execute($intent->id)));
        $this->assertSame('STAGED', $staged['blob']->refresh()->health);
    }

    public function test_staging_the_same_request_twice_resolves_the_first_blob_and_other_bytes_are_refused(): void
    {
        $intent = $this->request()['intent'];
        $first = $this->stage($intent, TestFiles::png());
        $again = app(StageUploadedFile::class)->execute($intent->id, TestFiles::stream(TestFiles::png()), 'logo.png', 'image/png', $this->user->id);

        $this->assertTrue($again['replayed']);
        $this->assertSame($first['blob']->id, $again['blob']->id);
        $this->assertCount(1, $this->objects(), 'the loser\'s object was removed');
        $this->assertSame([1, 1], [DB::table('file_blobs')->count(), DB::table('file_blob_events')->count()]);
        $other = substr(TestFiles::png(), 0, -1).'X';
        $this->assertSame(strlen(TestFiles::png()), strlen($other));
        $this->assertSame('intent_already_staged', $this->refusal(fn () => app(StageUploadedFile::class)->execute($intent->id, TestFiles::stream($other), 'logo.png', 'image/png', $this->user->id)));
        $this->assertCount(1, $this->objects());
    }

    public function test_a_failure_while_recording_removes_the_stored_object_and_leaves_no_row(): void
    {
        $intent = $this->request()['intent'];
        FileBlob::creating(fn () => throw new \RuntimeException('the database failed'));
        try {
            $this->stage($intent, TestFiles::png());
            $this->fail('The failure must surface.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            FileBlob::flushEventListeners();
        }
        $this->assertSame([], $this->objects());
        $this->assertSame([0, 0], [DB::table('file_blobs')->count(), DB::table('file_blob_events')->count()]);
        $this->assertSame('PENDING', $intent->refresh()->state, 'the request can still be served');
    }

    public function test_name_sanitising_never_yields_a_path_or_control_characters(): void
    {
        foreach (['../../etc/passwd', '..\\..\\win.ini', "a\x00b.png", "tab\there.png", '   .hidden. ', '', 'C:\\x\\y.PNG'] as $name) {
            $clean = FileValidator::sanitiseName($name);
            $this->assertStringNotContainsString('/', $clean);
            $this->assertStringNotContainsString('\\', $clean);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $clean);
            $this->assertNotSame('', $clean);
            $this->assertLessThanOrEqual(200, mb_strlen($clean));
        }
        $this->assertSame(200, mb_strlen(FileValidator::sanitiseName(str_repeat('a', 500))));
    }

    // ---- verifying -----------------------------------------------------------------------------------------------------------------------------------

    public function test_an_internal_purpose_verifies_with_the_default_scanner_saying_nothing_was_scanned(): void
    {
        $this->assertInstanceOf(NotConfiguredScanner::class, app(FileScanner::class));
        $intent = $this->request()['intent'];
        $this->stage($intent, TestFiles::png());
        $result = app(VerifyStagedFile::class)->execute($intent->id);

        $this->assertFalse($result['replayed']);
        $blob = $result['blob'];
        $this->assertSame(['VERIFIED', 'NOT_CONFIGURED'], [$blob->health, $blob->scan_state]);
        $this->assertNotNull($blob->verified_at);
        $event = FileBlobEvent::query()->where('event', 'VERIFIED')->sole();
        $this->assertSame(['STAGED', 'VERIFIED', $blob->sha256, $blob->sha256], [$event->from_health, $event->to_health, $event->expected_sha256, $event->observed_sha256]);
        // Repeating changes nothing and writes nothing.
        $count = $this->counts();
        $this->assertTrue(app(VerifyStagedFile::class)->execute($intent->id)['replayed']);
        $this->assertSame($count, $this->counts());
    }

    public function test_an_externally_supplied_file_is_never_verified_without_a_clean_scan(): void
    {
        $pdf = TestFiles::pdf();
        $intent = $this->request(TestOwnerAdapter::DOCUMENT, strlen($pdf))['intent'];
        $this->stage($intent, $pdf, 'quote.pdf', 'application/pdf');

        $first = app(VerifyStagedFile::class)->execute($intent->id)['blob'];
        $this->assertSame(['STAGED', 'NOT_CONFIGURED'], [$first->health, $first->scan_state], 'no scanner: not downloadable');
        $second = app(VerifyStagedFile::class)->execute($intent->id);
        $this->assertTrue($second['replayed']);
        $this->assertSame(0, FileBlobEvent::query()->where('event', 'SCAN_RESULT')->count(), 'NOT_CONFIGURED is already the recorded state: nothing new to record');

        foreach ([[ScanResult::Error, 'STAGED'], [ScanResult::Error, 'STAGED'], [ScanResult::Pending, 'STAGED'], [ScanResult::Clean, 'VERIFIED']] as [$scan, $health]) {
            $this->app->instance(FileScanner::class, $this->scanner($scan));
            $this->assertSame($health, app(VerifyStagedFile::class)->execute($intent->id)['blob']->health, $scan->value);
        }
        $this->assertSame(2, FileBlobEvent::query()->where('event', 'SCAN_RESULT')->count(), 'ERROR once (not on the repeat), then PENDING');
        $this->assertSame('CLEAN', FileBlob::query()->sole()->scan_state);

        $infected = $this->request(TestOwnerAdapter::DOCUMENT, strlen($pdf))['intent'];
        $this->stage($infected, $pdf, 'bad.pdf', 'application/pdf');
        $this->app->instance(FileScanner::class, $this->scanner(ScanResult::Infected));
        $blob = app(VerifyStagedFile::class)->execute($infected->id)['blob'];
        $this->assertSame(['QUARANTINED', 'INFECTED'], [$blob->health, $blob->scan_state]);
    }

    private function scanner(ScanResult $result): FileScanner
    {
        return new class($result) implements FileScanner
        {
            public function __construct(private readonly ScanResult $result) {}

            public function scan(string $disk, string $storageKey): ScanResult
            {
                return $this->result;
            }
        };
    }

    public function test_an_object_that_changed_or_vanished_is_rejected_and_never_verified(): void
    {
        $changed = $this->request()['intent'];
        $blob = $this->stage($changed, TestFiles::png())['blob'];
        Storage::disk('files')->put(substr($blob->storage_key, 0, 2).'/'.$blob->storage_key, TestFiles::png().'tampered');
        $rejected = app(VerifyStagedFile::class)->execute($changed->id)['blob'];
        $this->assertSame('REJECTED', $rejected->health);
        $this->assertSame('hash_mismatch', FileBlobEvent::query()->where('blob_id', $blob->id)->where('event', 'REJECTED')->value('reason'));
        $this->assertSame('file_rejected', $this->refusal(fn () => app(VerifyStagedFile::class)->execute($changed->id)));

        $gone = $this->request()['intent'];
        $other = $this->stage($gone, TestFiles::png())['blob'];
        Storage::disk('files')->delete(substr($other->storage_key, 0, 2).'/'.$other->storage_key);
        $this->assertSame('REJECTED', app(VerifyStagedFile::class)->execute($gone->id)['blob']->health);
        $this->assertSame('object_missing', FileBlobEvent::query()->where('blob_id', $other->id)->where('event', 'REJECTED')->value('reason'));

        $pending = $this->request()['intent'];
        $this->assertSame('intent_not_staged', $this->refusal(fn () => app(VerifyStagedFile::class)->execute($pending->id)));
    }

    // ---- no way in -----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_disk_is_private_and_nothing_is_reachable_registered_or_granted(): void
    {
        $disk = config('filesystems.disks.files');
        $this->assertSame(['local', 'private', false, true], [$disk['driver'], $disk['visibility'], $disk['serve'], $disk['throw']]);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertStringNotContainsString('public', (string) $disk['root']);
        $this->assertNotSame(config('filesystems.disks.public.root'), $disk['root']);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#(^|/)(files?|uploads?|attachments?|blobs?)(/|$)#', $u) === 1)->values()->all());
        $this->assertSame([], array_values(array_filter(app(PermissionRegistry::class)->all(), fn ($p) => str_starts_with($p, 'files.') || str_contains($p, 'upload'))));
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Files/Http'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Files/routes.php'));
        foreach (['file_attachments', 'file_attachment_versions', 'file_holds'] as $later) {
            $this->assertFalse(Schema::hasTable($later), "{$later} belongs to a later slice");
        }
        $this->assertSame(['app/Modules/Files'], [str_replace(base_path().'/', '', dirname(base_path('app/Modules/Files/FilesServiceProvider.php')))]);
    }
}
