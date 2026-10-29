<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Models\FileBlobEvent;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileAccessGate;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Compares one VERIFIED blob with the object actually stored: a missing object makes it MISSING (the file is unavailable and must be restored from a verified copy — a
 * regenerated file is NOT a recovery), an object whose hash or size differs makes it QUARANTINED as corrupt. Both leave evidence; an intact object, or a blob that is not
 * VERIFIED, changes nothing. Never deletes anything. The owner is asked under operation `reconcile` with its own identity for the blob. Internal.
 */
final class ReconcileFileBlob
{
    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company) {}

    /** @return 'ok'|'missing'|'corrupt'|'unchanged' */
    public function execute(int $blobId, string $ownerTypeKey, string $purposeKey, string $ownerBindingIdentity, ?int $actorId = null): string
    {
        $adapter = $this->registry->get($ownerTypeKey);
        $this->registry->purpose($ownerTypeKey, $purposeKey);
        FileBlob::query()->find($blobId) ?? throw new FileException('The file was not found.', 'blob_id', 'blob_not_found');
        $this->assertOwned($blobId, $ownerTypeKey, $purposeKey, $ownerBindingIdentity);
        if (! FileAccessGate::allows($adapter, new FileOperationContext($this->company->id(), $actorId, 'reconcile', $ownerTypeKey, $purposeKey, $ownerBindingIdentity))) {
            throw new FileException("The owner of '{$ownerTypeKey}' did not authorise this operation.", null, 'access_denied');
        }

        return DB::transaction(function () use ($blobId, $actorId): string {
            $blob = FileBlob::query()->whereKey($blobId)->lockForUpdate()->firstOrFail();
            if ($blob->health !== 'VERIFIED') {
                return 'unchanged';
            }
            $observed = StoredObject::observe($blob->storage_disk, StoredObject::path($blob->storage_key));
            if ($observed !== null && $observed['sha256'] === $blob->sha256 && $observed['size'] === $blob->size_bytes) {
                return 'ok';
            }
            $missing = $observed === null;
            $to = $missing ? 'MISSING' : 'QUARANTINED';
            $blob->update(['health' => $to]);
            FileBlobEvent::create([
                'blob_id' => $blob->id, 'event' => $missing ? 'MISSING_DETECTED' : 'QUARANTINED', 'from_health' => 'VERIFIED', 'to_health' => $to, 'expected_sha256' => $blob->sha256,
                'observed_sha256' => $observed['sha256'] ?? null, 'actor_id' => $actorId, 'service' => $actorId === null ? 'files.reconcile' : null, 'reason' => $missing ? 'object_missing' : 'object_corrupt',
                'evidence' => ['expected_size' => $blob->size_bytes, 'observed_size' => $observed['size'] ?? null], 'occurred_at' => now(),
            ]);

            return $missing ? 'missing' : 'corrupt';
        });
    }

    /** The owner asked about must be the owner of THIS file: an identity of another document never authorises work on this blob. */
    private function assertOwned(int $blobId, string $ownerTypeKey, string $purposeKey, string $ownerBindingIdentity): void
    {
        $owned = FileUploadIntent::query()->where(['blob_id' => $blobId, 'owner_type_key' => $ownerTypeKey, 'purpose_key' => $purposeKey, 'owner_binding_identity' => $ownerBindingIdentity])->exists();
        if (! $owned) {
            throw new FileException('The file was not found.', 'blob_id', 'blob_not_found');
        }
    }
}
