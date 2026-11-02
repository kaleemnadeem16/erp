<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Actions\StreamAuthorisedDocument;
use App\Modules\Documents\Support\DocumentDownload;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the stored official PDF of an issued quotation: `sales.document.download` and the right to see the quotation, then the document owner's decision and the
 * bytes' hash check inside Documents. Every hand-off is recorded (download or print) and is never receipt by the customer. No public or signed URL, ever.
 */
final class DownloadQuotationDocument
{
    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly StreamAuthorisedDocument $documents) {}

    public function __invoke(User $actor, int $revisionId, string $kind = 'DOWNLOAD'): DocumentDownload
    {
        // The bytes are copied to a private temporary stream inside execute(), so the hand-off record and the authority share this transaction.
        return DB::transaction(function () use ($actor, $revisionId, $kind): DocumentDownload {
            SalesAccess::require($actor, PermissionVocabulary::DOCUMENT_DOWNLOAD, 'download the quotation document');
            $this->locator->authorise($actor, $revisionId, 'download the quotation document');

            return $this->documents->execute($this->locator->artefactOrFail($revisionId)->id, $actor->id, $kind);
        });
    }
}
