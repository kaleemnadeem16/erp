<?php

declare(strict_types=1);

namespace App\Modules\Company\Actions;

use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\Brand\CompanyBrand;
use App\Modules\Company\Support\Brand\CompanyBrandLogo;

/**
 * The typed Company contract for branding (ADR 0162): read-only, always the current company's (never a caller-supplied company), either the current brand or one exact version.
 * "No brand" is null — nothing was ever set, or the latest version cleared everything — and a consumer must treat it as a normal case, not substitute a brand of its own. Needs no
 * duty: the brand is printed on documents any signed-in person may be handed, and Documents pins it into a manifest in the owner's own transaction.
 */
final class ReadCompanyBrand
{
    /** The latest version row, even an empty one (the Company screen shows its number and who made it). */
    public function latest(): ?CompanyBrandVersion
    {
        return CompanyBrandVersion::query()->orderByDesc('version')->first();
    }

    public function current(): ?CompanyBrand
    {
        $latest = $this->latest();

        return $latest === null ? null : self::contract($latest);
    }

    public function version(int $version): ?CompanyBrand
    {
        $row = CompanyBrandVersion::query()->where('version', $version)->first();

        return $row === null ? null : self::contract($row);
    }

    public static function contract(CompanyBrandVersion $row): ?CompanyBrand
    {
        if ($row->isEmpty()) {
            return null;
        }
        $logo = $row->logo_blob_id === null ? null : new CompanyBrandLogo((int) $row->logo_blob_id, (string) $row->logo_sha256, (string) $row->logo_media_type,
            (int) $row->logo_width, (int) $row->logo_height, (int) $row->logo_size_bytes);

        return new CompanyBrand($row->version, $row->display_name, $row->tagline, $logo);
    }
}
