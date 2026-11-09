<?php

declare(strict_types=1);

namespace App\Modules\Company\Support\Brand;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Company\Support\CompanyAccess;
use App\Modules\Files\Support\FileAccess;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerAdapter;
use App\Modules\Files\Support\FilePurpose;
use App\Support\CompanyContext;

/**
 * Company as an owner of files in the shared Files store (ADR 0153, ADR 0162): its only purpose is the company logo, PNG or JPEG, at most 2 MB and 1 megapixel. The bytes are
 * sanitised by Company before they reach Files, so the purpose is internally authored. Producing the file (upload, stage, verify) needs the branding duty exactly as the Action
 * does; `read` is the trusted in-process read Documents makes through the Company contract for the logo a manifest pinned (no actor), and is limited to this company's own binding.
 * A logo that went missing is NOT replaced by a new upload (a document pinned the original): `reconcile` and `restore` are offered to the branding duty only, and only the exact recorded bytes can be put back (ADR 0175).
 */
final class CompanyBrandFileOwner implements FileAccess, FileOwnerAdapter
{
    public const OWNER = 'company.brand-asset';

    public const PURPOSE = 'company.brand-logo';

    public const MAX_BYTES = 2_097_152;

    public const MAX_PIXELS = 1_000_000;

    public static function identity(int $companyId): string
    {
        return 'company:'.$companyId;
    }

    public function ownerTypeKey(): string
    {
        return self::OWNER;
    }

    public function purposes(): array
    {
        return [new FilePurpose(self::PURPOSE, ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg']], self::MAX_BYTES, false, self::MAX_PIXELS)];
    }

    public function access(): FileAccess
    {
        return $this;
    }

    public function authorise(FileOperationContext $context): bool
    {
        if ($context->ownerTypeKey !== self::OWNER || $context->purposeKey !== self::PURPOSE || $context->companyId !== app(CompanyContext::class)->id()
            || $context->ownerBindingIdentity !== self::identity($context->companyId)) {
            return false;
        }
        if ($context->operation === 'read') {
            return true;
        }
        if (! in_array($context->operation, ['upload', 'stage', 'verify', 'reconcile', 'restore'], true) || $context->actorId === null) {
            return false;
        }
        $member = User::query()->where('company_id', $context->companyId)->whereKey($context->actorId)->first();

        return $member !== null && $member->status === MemberStatus::Active && CompanyAccess::mayManageBranding($member);
    }
}
