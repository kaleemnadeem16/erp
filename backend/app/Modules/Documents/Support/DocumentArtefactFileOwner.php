<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Files\Support\FileAccess;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerAdapter;
use App\Modules\Files\Support\FilePurpose;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents as an owner of files in the shared Files store (ADR 0153): its only purpose is the generated PDF, produced inside the company by the renderer (never supplied
 * from outside). Documents never authorises a file operation on its own: it says yes only for the Files operations its own Actions perform, and only for a manifest or artefact
 * that exists in this company — the document owner's access decision has already been asked by that Action. Anything else, and anything it does not recognise, is denied.
 */
final class DocumentArtefactFileOwner implements FileAccess, FileOwnerAdapter
{
    public const OWNER = 'documents.artefact';

    public const PURPOSE = 'documents.artefact-pdf';

    public const MAX_BYTES = 20_971_520;

    public function ownerTypeKey(): string
    {
        return self::OWNER;
    }

    public function purposes(): array
    {
        return [new FilePurpose(self::PURPOSE, ['application/pdf' => ['pdf']], self::MAX_BYTES, false)];
    }

    public function access(): FileAccess
    {
        return $this;
    }

    public function authorise(FileOperationContext $context): bool
    {
        if ($context->ownerTypeKey !== self::OWNER || $context->purposeKey !== self::PURPOSE) {
            return false;
        }

        return match ($context->operation) {
            'upload', 'stage', 'verify' => $this->exists(DocumentManifest::class, 'manifest:', $context->ownerBindingIdentity),
            'reconcile', 'restore' => $this->exists(DocumentArtefact::class, 'artefact:', $context->ownerBindingIdentity),
            default => false,
        };
    }

    /** @param class-string<Model> $model */
    private function exists(string $model, string $prefix, string $identity): bool
    {
        if (preg_match('/^'.preg_quote($prefix, '/').'([1-9]\d{0,17})$/', $identity, $m) !== 1) {
            return false;
        }

        return $model::query()->whereKey((int) $m[1])->exists();
    }
}
