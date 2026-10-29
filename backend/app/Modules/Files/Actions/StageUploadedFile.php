<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Models\FileBlobEvent;
use App\Modules\Files\Models\FileOperationReceipt;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileAccessGate;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\FileRejection;
use App\Modules\Files\Support\FileValidator;
use App\Modules\Files\Support\ValidatedUpload;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Takes the bytes of one requested upload, validates them in a private temporary copy (ceiling while streaming, sniffed type on the purpose's allowlist, extension,
 * declared type and image decoding) and stores them under an opaque random key on the private `files` disk, recording the blob as STAGED with its evidence and moving the
 * intent to STAGED — all in one short transaction after the object is written, removing the object if that transaction fails. Content refused after being read in full is
 * recorded as a REJECTED blob (hash, size and sniffed type; no object kept); an upload over the ceiling or empty aborts the intent. A second stage of the same intent
 * with the same bytes resolves the first blob; with other bytes it is refused. Owner access is asked again. Nothing here makes a file downloadable. Internal.
 */
final class StageUploadedFile
{
    public const OPERATION = 'stage_upload';

    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company, private readonly FileValidator $validator) {}

    /**
     * @param  resource  $stream
     * @return array{blob: FileBlob, intent: FileUploadIntent, replayed: bool}
     */
    public function execute(int $intentId, $stream, ?string $declaredName = null, ?string $declaredMediaType = null, ?int $actorId = null): array
    {
        $intent = FileUploadIntent::query()->find($intentId) ?? throw new FileException('The upload request was not found.', 'intent_id', 'intent_not_found');
        $adapter = $this->registry->get($intent->owner_type_key);
        $purpose = $this->registry->purpose($intent->owner_type_key, $intent->purpose_key);
        $actorId ??= $intent->actor_id;
        if (! FileAccessGate::allows($adapter, new FileOperationContext($this->company->id(), $actorId, 'stage', $intent->owner_type_key, $intent->purpose_key, $intent->owner_binding_identity))) {
            throw new FileException("The owner of '{$intent->owner_type_key}' did not authorise this operation.", null, 'access_denied');
        }
        if (in_array($intent->state, ['EXPIRED', 'ABORTED', 'COMMITTED'], true)) {
            throw new FileException('That upload request is closed.', 'intent_id', 'intent_closed');
        }
        if ($intent->state === 'PENDING' && $this->expired($intent)) {
            $this->settle($intent->id, 'EXPIRED', null);
            throw new FileException('That upload request has expired; ask for a new one.', 'intent_id', 'intent_expired');
        }

        try {
            $upload = $this->validator->inspect($stream, $purpose, $declaredName, $declaredMediaType);
            if ($intent->expected_size !== $upload->size) {
                throw new FileRejection('The file size is not the size that was announced.', 'file', 'size_mismatch', $upload->sha256, $upload->size, $upload->mediaType);
            }
            if ($intent->expected_sha256 !== null && $intent->expected_sha256 !== $upload->sha256) {
                throw new FileRejection('The file hash is not the hash that was announced.', 'file', 'hash_mismatch', $upload->sha256, $upload->size, $upload->mediaType);
            }
        } catch (FileRejection $rejection) {
            $this->recordRejection($intent, $rejection, $actorId);
            throw $rejection;
        } catch (FileException $e) {
            if (in_array($e->reasonCode, ['size_exceeded', 'file_empty'], true)) {
                $this->settle($intent->id, 'ABORTED', (string) $e->reasonCode);
            }
            throw $e;
        }

        $key = bin2hex(random_bytes(20));
        $path = $this->path($key);
        try {
            Storage::disk('files')->writeStream($path, $upload->handle);
        } catch (Throwable $e) {
            report($e);
            $this->discard($path);
            throw new FileException('The file could not be stored.', null, 'storage_failed');
        }

        try {
            return DB::transaction(fn (): array => $this->commit($intent->id, $upload, $key, $path, $actorId));
        } catch (FileException $e) {
            $this->discard($path);
            throw $e;
        } catch (Throwable $e) {
            // Database and storage errors carry paths, keys and bindings: they go to the log, and the caller gets a fixed message with no chained cause.
            report($e);
            $this->discard($path);
            throw new FileException('The file could not be recorded.', null, 'record_failed');
        }
    }

    /** @return array{blob: FileBlob, intent: FileUploadIntent, replayed: bool} */
    private function commit(int $intentId, ValidatedUpload $upload, string $key, string $path, ?int $actorId): array
    {
        $intent = FileUploadIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
        if ($intent->state === 'STAGED') {
            // Someone staged it first: the same bytes resolve their blob, other bytes are refused. Either way our object is not needed.
            $this->discard($path);
            $existing = FileBlob::query()->findOrFail($intent->blob_id);
            if ($existing->sha256 !== $upload->sha256) {
                throw new FileException('That upload request already holds a different file.', 'intent_id', 'intent_already_staged');
            }

            return ['blob' => $existing, 'intent' => $intent, 'replayed' => true];
        }
        if ($intent->state !== 'PENDING') {
            $this->discard($path);
            throw new FileException('That upload request is closed.', 'intent_id', 'intent_closed');
        }
        if ($this->expired($intent)) {
            $this->discard($path);
            throw new FileException('That upload request has expired; ask for a new one.', 'intent_id', 'intent_expired');
        }
        $blob = FileBlob::create([
            'storage_disk' => 'files', 'storage_key' => $key, 'sha256' => $upload->sha256, 'size_bytes' => $upload->size, 'detected_media_type' => $upload->mediaType,
            'declared_media_type' => $upload->declaredMediaType, 'health' => 'STAGED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $actorId, 'created_at' => now(),
        ])->refresh();
        $this->event($blob, 'STAGED', null, 'STAGED', $actorId, null, ['size' => $upload->size, 'media_type' => $upload->mediaType], $upload->sha256, $upload->sha256);
        $intent->update(['state' => 'STAGED', 'blob_id' => $blob->id]);
        FileOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => 'stage:'.$intent->id, 'intent_digest' => $intent->intent_digest, 'intent_id' => $intent->id,
            'blob_id' => $blob->id, 'actor_id' => $actorId, 'recorded_at' => now()]);

        return ['blob' => $blob, 'intent' => $intent->refresh(), 'replayed' => false];
    }

    /** Content that was read and refused is evidence, not a stored file: a REJECTED blob row with what was observed, no object, and the intent aborted. */
    private function recordRejection(FileUploadIntent $intent, FileRejection $rejection, ?int $actorId): void
    {
        DB::transaction(function () use ($intent, $rejection, $actorId): void {
            $locked = FileUploadIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== 'PENDING') {
                return;
            }
            $blob = FileBlob::create([
                'storage_disk' => 'files', 'storage_key' => bin2hex(random_bytes(20)), 'sha256' => $rejection->sha256, 'size_bytes' => $rejection->size,
                'detected_media_type' => $rejection->detectedMediaType, 'declared_media_type' => null, 'health' => 'REJECTED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $actorId, 'created_at' => now(),
            ])->refresh();
            $this->event($blob, 'REJECTED', null, 'REJECTED', $actorId, (string) $rejection->reasonCode, ['size' => $rejection->size, 'media_type' => $rejection->detectedMediaType], $locked->expected_sha256, $rejection->sha256);
            $locked->update(['state' => 'ABORTED', 'error_code' => (string) $rejection->reasonCode, 'blob_id' => $blob->id]);
        });
    }

    private function settle(int $intentId, string $state, ?string $errorCode): void
    {
        DB::transaction(function () use ($intentId, $state, $errorCode): void {
            $locked = FileUploadIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($locked->state === 'PENDING') {
                $locked->update(['state' => $state, 'error_code' => $errorCode]);
            }
        });
    }

    /** @param array<string, mixed> $evidence */
    private function event(FileBlob $blob, string $event, ?string $from, string $to, ?int $actorId, ?string $reason, array $evidence, ?string $expected, ?string $observed): void
    {
        FileBlobEvent::create([
            'blob_id' => $blob->id, 'event' => $event, 'from_health' => $from, 'to_health' => $to, 'expected_sha256' => $expected, 'observed_sha256' => $observed,
            'actor_id' => $actorId, 'service' => $actorId === null ? 'files.stage' : null, 'reason' => $reason, 'evidence' => $evidence, 'occurred_at' => now(),
        ]);
    }

    private function expired(FileUploadIntent $intent): bool
    {
        return (bool) DB::selectOne('select clock_timestamp() >= ?::timestamptz as expired', [$intent->getRawOriginal('expires_at')])->expired;
    }

    private function path(string $key): string
    {
        return substr($key, 0, 2).'/'.$key;
    }

    private function discard(string $path): void
    {
        try {
            Storage::disk('files')->delete($path);
        } catch (Throwable) {
            // A leftover staging object is an orphan the reconciliation slice (F2) finds; it must not mask the real outcome.
        }
    }
}
