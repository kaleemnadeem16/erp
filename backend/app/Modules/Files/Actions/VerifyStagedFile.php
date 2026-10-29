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
use App\Modules\Files\Support\FilePurpose;
use App\Modules\Files\Support\FileScanner;
use App\Modules\Files\Support\ScanResult;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Re-reads a staged object, recomputes its hash and size against the recorded identity, asks the scanner and settles the blob's health with evidence. A missing object or a
 * different hash is REJECTED; an infected one is QUARANTINED. An internally authored purpose becomes VERIFIED once the object is intact and not infected (the scan state
 * says what was or was not scanned). A purpose whose files come from outside the company becomes VERIFIED only on a CLEAN scan: with the default scanner (NOT_CONFIGURED)
 * it stays STAGED, so nothing externally supplied can become downloadable (D3). Repeating a settled verification changes nothing. Owner access is asked again. Internal.
 */
final class VerifyStagedFile
{
    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company, private readonly FileScanner $scanner) {}

    /** @return array{blob: FileBlob, replayed: bool} */
    public function execute(int $intentId, ?int $actorId = null): array
    {
        $intent = FileUploadIntent::query()->find($intentId) ?? throw new FileException('The upload request was not found.', 'intent_id', 'intent_not_found');
        $adapter = $this->registry->get($intent->owner_type_key);
        $purpose = $this->registry->purpose($intent->owner_type_key, $intent->purpose_key);
        $actorId ??= $intent->actor_id;
        if (! FileAccessGate::allows($adapter, new FileOperationContext($this->company->id(), $actorId, 'verify', $intent->owner_type_key, $intent->purpose_key, $intent->owner_binding_identity))) {
            throw new FileException("The owner of '{$intent->owner_type_key}' did not authorise this operation.", null, 'access_denied');
        }
        if ($intent->state !== 'STAGED' || $intent->blob_id === null) {
            throw new FileException('Only a staged upload can be verified.', 'intent_id', 'intent_not_staged');
        }

        try {
            return $this->verifyLocked($intent, $purpose, $actorId);
        } catch (FileException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new FileException('The file could not be verified.', null, 'verify_failed');
        }
    }

    /** @return array{blob: FileBlob, replayed: bool} */
    private function verifyLocked(FileUploadIntent $intent, FilePurpose $purpose, ?int $actorId): array
    {
        return DB::transaction(function () use ($intent, $purpose, $actorId): array {
            $blob = FileBlob::query()->whereKey($intent->blob_id)->lockForUpdate()->firstOrFail();
            if ($blob->health !== 'STAGED') {
                if ($blob->health === 'REJECTED') {
                    throw new FileException('That file was rejected.', 'intent_id', 'file_rejected');
                }

                return ['blob' => $blob, 'replayed' => true];
            }
            $path = StoredObject::path($blob->storage_key);
            $observed = StoredObject::observe($blob->storage_disk, $path);
            if ($observed === null) {
                return ['blob' => $this->settle($blob, 'REJECTED', $blob->scan_state, 'REJECTED', 'object_missing', $actorId, null), 'replayed' => false];
            }
            if ($observed['sha256'] !== $blob->sha256 || $observed['size'] !== $blob->size_bytes) {
                return ['blob' => $this->settle($blob, 'REJECTED', $blob->scan_state, 'REJECTED', 'hash_mismatch', $actorId, $observed['sha256']), 'replayed' => false];
            }
            $scan = $this->scanner->scan($blob->storage_disk, $path);
            if ($scan === ScanResult::Infected) {
                return ['blob' => $this->settle($blob, 'QUARANTINED', $scan->value, 'QUARANTINED', 'infected', $actorId, $observed['sha256']), 'replayed' => false];
            }
            $clean = $scan === ScanResult::Clean;
            $acceptable = $purpose->externallySupplied ? $clean : ($clean || $scan === ScanResult::NotConfigured);
            if ($acceptable) {
                return ['blob' => $this->settle($blob, 'VERIFIED', $scan->value, 'VERIFIED', null, $actorId, $observed['sha256']), 'replayed' => false];
            }
            // Not yet acceptable (an external file without a clean scan, or a scan error): it stays STAGED and the scan state is recorded once.
            if ($blob->scan_state !== $scan->value) {
                return ['blob' => $this->settle($blob, 'STAGED', $scan->value, 'SCAN_RESULT', 'scan_'.strtolower($scan->value), $actorId, $observed['sha256']), 'replayed' => false];
            }

            return ['blob' => $blob, 'replayed' => true];
        });
    }

    private function settle(FileBlob $blob, string $health, string $scanState, string $event, ?string $reason, ?int $actorId, ?string $observedHash): FileBlob
    {
        $from = $blob->health;
        $blob->update(['health' => $health, 'scan_state' => $scanState]);
        FileBlobEvent::create([
            'blob_id' => $blob->id, 'event' => $event, 'from_health' => $from, 'to_health' => $health, 'expected_sha256' => $blob->sha256, 'observed_sha256' => $observedHash,
            'actor_id' => $actorId, 'service' => $actorId === null ? 'files.verify' : null, 'reason' => $reason, 'evidence' => ['scan_state' => $scanState], 'occurred_at' => now(),
        ]);

        return $blob->refresh();
    }
}
