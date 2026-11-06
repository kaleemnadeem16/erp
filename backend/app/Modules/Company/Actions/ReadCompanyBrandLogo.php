<?php

declare(strict_types=1);

namespace App\Modules\Company\Actions;

use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\Brand\CompanyBrandFileOwner;
use App\Modules\Company\Support\Brand\CompanyBrandLogo;
use App\Modules\Files\Actions\ReadVerifiedFile;
use App\Modules\Files\Support\FileException;
use App\Support\CompanyContext;

/**
 * Reads the bytes of a logo that a brand version of THIS company holds, for the one trusted purpose of printing it (Documents, from the reference a manifest pinned) or showing the
 * current one on the Company screen. The reference must match a version's blob and hash exactly; Files then checks the owner, that the file is VERIFIED and that the object still has
 * the recorded hash and size. A missing or altered file is a refusal (`file_unavailable`), never a silent unbranded document. Internal: callers authorise their own use.
 */
final class ReadCompanyBrandLogo
{
    public function __construct(private readonly ReadVerifiedFile $files, private readonly CompanyContext $company) {}

    public function execute(CompanyBrandLogo $logo, ?int $actorId = null): string
    {
        $held = CompanyBrandVersion::query()->where(['logo_blob_id' => $logo->blobId, 'logo_sha256' => $logo->sha256])->exists();
        if (! $held) {
            throw new FileException('That logo is not one of this company\'s.', 'blob_id', 'file_not_found');
        }

        return $this->files->execute($logo->blobId, CompanyBrandFileOwner::OWNER, CompanyBrandFileOwner::PURPOSE, CompanyBrandFileOwner::identity($this->company->id()), $actorId);
    }
}
