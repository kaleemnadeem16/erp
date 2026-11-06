<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileAccessGate;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Hands a trusted module the bytes of one small VERIFIED file it owns, for its own use (a logo it prints, a preview it shows) — never a route, never a URL. The blob must be the
 * company's, must have been staged for exactly this owner, purpose and binding, must be VERIFIED, and the owner is asked (`read`) every time. The object is read through a size
 * ceiling and compared to the recorded hash and size before anything is returned, so a missing, grown or altered object is a refusal, not a surprise. Internal.
 */
final class ReadVerifiedFile
{
    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(int $blobId, string $ownerTypeKey, string $purposeKey, string $ownerBindingIdentity, ?int $actorId = null): string
    {
        $adapter = $this->registry->get($ownerTypeKey);
        $purpose = $this->registry->purpose($ownerTypeKey, $purposeKey);
        if (! FileAccessGate::allows($adapter, new FileOperationContext($this->company->id(), $actorId, 'read', $ownerTypeKey, $purposeKey, $ownerBindingIdentity))) {
            throw new FileException("The owner of '{$ownerTypeKey}' did not authorise this operation.", null, 'access_denied');
        }
        $blob = FileBlob::query()->find($blobId);
        $staged = $blob === null ? false : FileUploadIntent::query()->where(['blob_id' => $blob->id, 'owner_type_key' => $ownerTypeKey, 'purpose_key' => $purposeKey, 'owner_binding_identity' => $ownerBindingIdentity])->exists();
        if ($blob === null || ! $staged) {
            throw new FileException('That file was not found.', 'blob_id', 'file_not_found');
        }
        if ($blob->health !== 'VERIFIED') {
            throw new FileException('That file is not available.', 'blob_id', 'file_unavailable');
        }
        if ($blob->size_bytes > $purpose->maxBytes) {
            throw new FileException('That file is larger than its purpose allows.', 'blob_id', 'file_unavailable');
        }
        try {
            $path = StoredObject::path($blob->storage_key);
            $stream = Storage::disk($blob->storage_disk)->readStream($path);
            $bytes = $stream === null ? false : stream_get_contents($stream, $purpose->maxBytes + 1);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable) {
            $bytes = false;
        }
        if ($bytes === false || strlen($bytes) !== $blob->size_bytes || hash('sha256', $bytes) !== $blob->sha256) {
            throw new FileException('That file is not available.', 'blob_id', 'file_unavailable');
        }

        return $bytes;
    }
}
