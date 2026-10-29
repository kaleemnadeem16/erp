<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Models\FileBlobEvent;
use App\Modules\Files\Support\FileAccessGate;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the EXACT bytes of a MISSING or QUARANTINED-as-corrupt blob back under its own key. The supplied bytes must match the recorded SHA-256 and size (checked in a private
 * temporary copy first, then again on the stored object) — a different file, however plausible, is refused; an infected blob is never restored. A reason is required and
 * the blob returns to VERIFIED with a RESTORED event. The identity of the blob never changes. The owner is asked under operation `restore`. Internal.
 */
final class RestoreFileBlob
{
    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company) {}

    /** @param  resource  $stream */
    public function execute(int $blobId, $stream, string $ownerTypeKey, string $purposeKey, string $ownerBindingIdentity, string $reason, ?int $actorId = null): FileBlob
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new FileException('A restore needs a reason of at most 255 characters.', 'reason', 'reason_required');
        }
        $adapter = $this->registry->get($ownerTypeKey);
        $this->registry->purpose($ownerTypeKey, $purposeKey);
        $blob = FileBlob::query()->find($blobId) ?? throw new FileException('The file was not found.', 'blob_id', 'blob_not_found');
        if (! FileAccessGate::allows($adapter, new FileOperationContext($this->company->id(), $actorId, 'restore', $ownerTypeKey, $purposeKey, $ownerBindingIdentity))) {
            throw new FileException("The owner of '{$ownerTypeKey}' did not authorise this operation.", null, 'access_denied');
        }
        if (! in_array($blob->health, ['MISSING', 'QUARANTINED'], true) || $blob->scan_state === 'INFECTED') {
            throw new FileException('Only a missing or corrupt, not infected, file can be restored.', 'blob_id', 'restore_not_applicable');
        }

        // Read the supplied bytes once into a private temporary file, comparing hash and size with the recorded identity before anything is stored.
        $temp = tmpfile();
        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                throw new FileException('The bytes could not be read.', null, 'upload_unreadable');
            }
            $size += strlen($chunk);
            if ($size > $blob->size_bytes) {
                throw new FileException('The bytes are not the recorded file.', 'file', 'restore_mismatch');
            }
            hash_update($hash, $chunk);
            fwrite($temp, $chunk);
        }
        if ($size !== $blob->size_bytes || hash_final($hash) !== $blob->sha256) {
            throw new FileException('The bytes are not the recorded file.', 'file', 'restore_mismatch');
        }
        rewind($temp);
        $path = StoredObject::path($blob->storage_key);
        Storage::disk($blob->storage_disk)->writeStream($path, $temp);
        $observed = StoredObject::observe($blob->storage_disk, $path);
        if ($observed === null || $observed['sha256'] !== $blob->sha256 || $observed['size'] !== $blob->size_bytes) {
            throw new FileException('The restored object could not be verified.', null, 'restore_unverified');
        }

        return DB::transaction(function () use ($blobId, $actorId, $reason): FileBlob {
                $locked = FileBlob::query()->whereKey($blobId)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->health, ['MISSING', 'QUARANTINED'], true)) {
                    return $locked;
                }
                $from = $locked->health;
                $locked->update(['health' => 'VERIFIED']);
                FileBlobEvent::create([
                    'blob_id' => $locked->id, 'event' => 'RESTORED', 'from_health' => $from, 'to_health' => 'VERIFIED', 'expected_sha256' => $locked->sha256, 'observed_sha256' => $locked->sha256,
                    'actor_id' => $actorId, 'service' => $actorId === null ? 'files.restore' : null, 'reason' => $reason, 'evidence' => ['size' => $locked->size_bytes], 'occurred_at' => now(),
                ]);

                return $locked->refresh();
        });
    }
}
