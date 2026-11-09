<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Resources;

use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\CompanyAccess;
use App\Modules\Company\Support\CompanyDutyManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The company's current document branding for the Company screen. `enabled` says whether the branding duty is live at all (it is STAGED today); `can_manage` whether this caller may
 * change it now. The logo is described, never embedded: the screen fetches it from the logo endpoint. A cleared brand (a latest version with nothing set) reads as no brand.
 *
 * @mixin CompanyBrandVersion
 */
class CompanyBrandingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CompanyBrandVersion|null $version */
        $version = $this->resource;
        $user = $request->user();

        return [
            'version' => $version?->version,
            'display_name' => $version?->display_name,
            'tagline' => $version?->tagline,
            'logo' => $version?->logo_blob_id === null ? null : [
                'media_type' => $version->logo_media_type, 'width' => $version->logo_width, 'height' => $version->logo_height, 'size_bytes' => $version->logo_size_bytes,
            ],
            // The internal note behind a change is for the people who may manage the brand; everyone else sees the brand itself.
            'reason' => $user !== null && CompanyAccess::mayManageBranding($user) ? $version?->reason : null,
            'changed_at' => $version?->created_at?->toIso8601String(),
            'enabled' => app(CompanyDutyManifest::class)->isActive(CompanyDutyManifest::BRANDING),
            'can_manage' => $user !== null && CompanyAccess::mayManageBranding($user),
            'logo_max_bytes' => 2_097_152,
            'logo_types' => ['image/png', 'image/jpeg'],
        ];
    }
}
