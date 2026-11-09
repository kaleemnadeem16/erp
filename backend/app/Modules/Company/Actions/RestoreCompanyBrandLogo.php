<?php

declare(strict_types=1);

namespace App\Modules\Company\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Models\CompanyChange;
use App\Modules\Company\Support\Brand\CompanyBrandFileOwner;
use App\Modules\Company\Support\CompanyAccess;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Files\Actions\ReconcileFileBlob;
use App\Modules\Files\Actions\RestoreFileBlob;
use App\Modules\Files\Support\FileException;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Brings back the logo file a brand version pinned when its stored object went missing or was damaged (ADR 0175). A document that pinned a brand can only ever print the exact
 * logo it pinned, so a new upload (a different file, a different reference) is no recovery. This takes a verified copy of the original bytes and puts them back under the SAME
 * blob: the bytes must match the recorded SHA-256 and size or nothing is stored. It never changes a brand version, never substitutes a newer logo and never touches a healthy
 * file. Needs the branding duty (`company.branding.manage`, asked here and again by the file owner) and a reason; it is internal (no route), like restoring a document's file.
 */
final class RestoreCompanyBrandLogo
{
    public function __construct(private readonly CompanyContext $company, private readonly ReconcileFileBlob $reconcile, private readonly RestoreFileBlob $restore) {}

    /**
     * @param  resource  $stream  a verified copy of the original (already sanitised) logo file
     * @return 'restored'|'intact'
     */
    public function execute(User $actor, int $version, $stream, string $reason): string
    {
        if (! $actor->can(CompanyDutyManifest::BRANDING)) {
            throw new DomainInvariantException('The actor is not permitted to change the company branding.');
        }
        CompanyAccess::requireBranding($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new DomainInvariantException('A reason of at most 255 characters is required to restore a company logo.', 'reason');
        }
        $row = CompanyBrandVersion::query()->where('version', $version)->first();
        if ($row === null || $row->logo_blob_id === null) {
            throw new DomainInvariantException('That brand version has no logo.', 'version', 'logo_not_found');
        }
        $identity = CompanyBrandFileOwner::identity($this->company->id());

        try {
            $outcome = $this->reconcile->execute((int) $row->logo_blob_id, CompanyBrandFileOwner::OWNER, CompanyBrandFileOwner::PURPOSE, $identity, $actor->id);
            if ($outcome === 'ok') {
                return 'intact';
            }
            $this->restore->execute((int) $row->logo_blob_id, $stream, CompanyBrandFileOwner::OWNER, CompanyBrandFileOwner::PURPOSE, $identity, $reason, $actor->id);
        } catch (FileException $e) {
            throw new DomainInvariantException($e->getMessage(), 'logo', 'logo_restore_'.$e->reasonCode);
        }
        DB::transaction(fn () => CompanyChange::query()->create([
            'section' => 'BRANDING', 'old_values' => ['brand_version' => $row->version, 'logo_sha256' => $row->logo_sha256], 'new_values' => ['brand_version' => $row->version, 'logo_sha256' => $row->logo_sha256, 'logo_restored' => true],
            'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
        ]));

        return 'restored';
    }
}
