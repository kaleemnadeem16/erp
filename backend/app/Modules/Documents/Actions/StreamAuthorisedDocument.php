<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentAccessEvent;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentDownload;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Storage;

/**
 * Secure delivery of one stored original or preview: the document owner decides (`download`, under its own duty and scope), the Files blob must be VERIFIED, and the bytes are
 * hash-checked before a single one is handed on. A missing or corrupt object is reported as unavailable (never served, never regenerated in its place). The result carries a
 * generated attachment name and fixed headers; no storage path, key or URL ever leaves. Internal: no route yet.
 */
final class StreamAuthorisedDocument
{
    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(int $artefactId, ?int $actorId = null, string $kind = 'DOWNLOAD'): DocumentDownload
    {
        if (! in_array($kind, ['DOWNLOAD', 'PRINT'], true)) {
            throw new DocumentException('That is not a kind of document access.', 'kind', 'access_kind_invalid');
        }
        if ($actorId === null) {
            throw new DocumentException('A document is only handed to a named member.', null, 'access_denied');
        }
        $artefact = DocumentArtefact::query()->find($artefactId) ?? throw new DocumentException('The document was not found.', 'artefact_id', 'artefact_not_found');
        $manifest = $artefact->manifest;
        $binding = $manifest->binding()->firstOrFail();
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($this->company->id(), $actorId, 'download', $binding->type_key, $binding->audience, $binding->owner_binding_identity,
            $manifest->purpose, $manifest->source_generation, $manifest->source_snapshot_reference, $manifest->stable_generation_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }
        try {
            $blob = FileBlob::query()->find($artefact->blob_id);
            if ($blob === null || $blob->health !== 'VERIFIED' || $blob->sha256 !== $artefact->sha256 || $blob->size_bytes !== $artefact->size_bytes) {
                throw new DocumentException('This document is not available.', 'artefact_id', 'artefact_unavailable');
            }
            // Read the object ONCE: the bytes are hashed while they are copied to a private temporary stream, and that copy is what is handed on, so what was checked is what
            // is delivered (a second open of the object could see different bytes).
            $copy = $this->snapshot($blob->storage_disk, StoredObject::path($blob->storage_key), $artefact);
        } catch (DocumentException $e) {
            if ($e->reasonCode === 'artefact_unavailable') {
                $this->record($artefact, $actorId, $kind, 'UNAVAILABLE');
            }
            throw $e;
        }
        // The hand-off is evidence: if it cannot be recorded, nothing is handed over.
        try {
            $this->record($artefact, $actorId, $kind, 'DELIVERED');
        } catch (\Throwable $e) {
            fclose($copy);
            throw $e;
        }
        $name = strtolower($manifest->purpose === 'ORIGINAL' ? 'document' : 'draft').'-'.$artefact->id.'.pdf';

        return new DocumentDownload($copy, $name, 'application/pdf', $artefact->size_bytes, $artefact->sha256);
    }

    /** @return resource */
    private function snapshot(string $disk, string $path, DocumentArtefact $artefact)
    {
        $unavailable = fn (): DocumentException => new DocumentException('This document is not available.', 'artefact_id', 'artefact_unavailable');
        try {
            $source = Storage::disk($disk)->readStream($path);
        } catch (\Throwable) {
            throw $unavailable();
        }
        if ($source === null) {
            throw $unavailable();
        }
        $copy = fopen('php://temp/maxmemory:1048576', 'r+');
        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($source)) {
            $chunk = fread($source, 65536);
            if ($chunk === false || ($size += strlen($chunk)) > $artefact->size_bytes) {
                throw $unavailable();
            }
            hash_update($hash, $chunk);
            fwrite($copy, $chunk);
        }
        fclose($source);
        if ($size !== $artefact->size_bytes || ! hash_equals($artefact->sha256, hash_final($hash))) {
            throw $unavailable();
        }
        rewind($copy);

        return $copy;
    }

    /** Hand-off history only: never receipt, read or acceptance by the customer. */
    private function record(DocumentArtefact $artefact, int $actorId, string $kind, string $outcome): void
    {
        DocumentAccessEvent::create(['artefact_id' => $artefact->id, 'actor_id' => $actorId, 'access_kind' => $kind, 'outcome' => $outcome, 'occurred_at' => now()]);
    }
}
