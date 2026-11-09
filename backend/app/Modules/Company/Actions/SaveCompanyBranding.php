<?php

declare(strict_types=1);

namespace App\Modules\Company\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Models\CompanyChange;
use App\Modules\Company\Support\Brand\CompanyBrandFileOwner;
use App\Modules\Company\Support\CompanyAccess;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Files\Actions\RequestFileUpload;
use App\Modules\Files\Actions\StageUploadedFile;
use App\Modules\Files\Actions\VerifyStagedFile;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\Images\ImageSanitiser;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Changes the company's document branding by appending a new immutable version (issue #23, ADR 0162). Needs `company.branding.manage` (STAGED: nobody holds it today) — asked
 * here and in the request — and a reason. Each of the three parts is independent: a part that is not named keeps its current value; a name or tagline given as null (or blank)
 * is cleared; a logo is replaced by an uploaded PNG or JPEG or removed. A logo is read with a 2 MB ceiling, sanitised to pixel data only (all metadata and unknown structure dropped), then
 * stored through Files (request, stage, verify) under the Company file owner, so only a VERIFIED object can ever be referenced. Idempotent by content: a change that leaves the brand as it is
 * writes nothing and returns the current version, so a retried request never makes a second version. One transaction under the company row lock numbers the version and writes it
 * with a CompanyChange audit row; stored files are never deleted (an earlier version still pins its logo).
 */
final class SaveCompanyBranding
{
    public const UNCHANGED = 'unchanged';

    public const MAX_LOGO_BYTES = CompanyBrandFileOwner::MAX_BYTES;

    public function __construct(
        private readonly CompanyContext $company,
        private readonly RequestFileUpload $uploads,
        private readonly StageUploadedFile $stage,
        private readonly VerifyStagedFile $verify,
    ) {}

    /**
     * @param  array{display_name?: string|null, tagline?: string|null, remove_logo?: bool}  $changes  only the keys present are changed
     * @param  resource|null  $logoStream  a new logo (PNG or JPEG), or null to leave the logo as it is
     * @return array{version: CompanyBrandVersion, changed: bool}
     */
    public function __invoke(User $actor, array $changes, mixed $logoStream, string $reason): array
    {
        if (! $actor->can(CompanyDutyManifest::BRANDING)) {
            throw new DomainInvariantException('The actor is not permitted to change the company branding.');
        }
        CompanyAccess::requireBranding($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainInvariantException('A reason of at most 1000 characters is required to change the company branding.', 'reason');
        }
        $clean = [];
        foreach (['display_name' => 120, 'tagline' => 160] as $key => $max) {
            if (array_key_exists($key, $changes)) {
                $value = $changes[$key] === null ? null : trim(preg_replace('/\s+/u', ' ', (string) $changes[$key]) ?? '');
                $value = $value === '' ? null : $value;
                if ($value !== null && (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1)) {
                    throw new DomainInvariantException("The {$key} must be plain text of at most {$max} characters.", $key);
                }
                $clean[$key] = $value;
            }
        }
        $removeLogo = (bool) ($changes['remove_logo'] ?? false);
        if ($removeLogo && $logoStream !== null) {
            throw new DomainInvariantException('Choose a new logo or remove the current one, not both.', 'logo');
        }
        $blob = $logoStream === null ? null : $this->storeLogo($actor, $logoStream);

        return DB::transaction(function () use ($actor, $clean, $removeLogo, $blob, $reason): array {
            // The company row is locked only to serialise version numbers.
            Company::query()->lockForUpdate()->findOrFail($this->company->id());
            $latest = CompanyBrandVersion::query()->orderByDesc('version')->first();
            $next = [
                'display_name' => array_key_exists('display_name', $clean) ? $clean['display_name'] : $latest?->display_name,
                'tagline' => array_key_exists('tagline', $clean) ? $clean['tagline'] : $latest?->tagline,
                'logo_blob_id' => $latest?->logo_blob_id, 'logo_sha256' => $latest?->logo_sha256, 'logo_media_type' => $latest?->logo_media_type,
                'logo_width' => $latest?->logo_width, 'logo_height' => $latest?->logo_height, 'logo_size_bytes' => $latest?->logo_size_bytes,
            ];
            if ($removeLogo) {
                $next = array_merge($next, ['logo_blob_id' => null, 'logo_sha256' => null, 'logo_media_type' => null, 'logo_width' => null, 'logo_height' => null, 'logo_size_bytes' => null]);
            }
            if ($blob !== null) {
                $next = array_merge($next, ['logo_blob_id' => $blob['blob']->id, 'logo_sha256' => $blob['blob']->sha256, 'logo_media_type' => $blob['media_type'],
                    'logo_width' => $blob['width'], 'logo_height' => $blob['height'], 'logo_size_bytes' => $blob['blob']->size_bytes]);
            }
            $current = $latest === null ? array_fill_keys(array_keys($next), null) : $latest->only(array_keys($next));
            if ($latest !== null ? $current == $next : ! array_filter($next, static fn (mixed $v): bool => $v !== null)) {
                return ['version' => $latest ?? $this->emptyBaseline(), 'changed' => false];
            }

            $version = CompanyBrandVersion::query()->create($next + ['version' => (int) CompanyBrandVersion::query()->max('version') + 1, 'reason' => $reason, 'created_by' => $actor->id])->refresh();
            CompanyChange::query()->create([
                'section' => 'BRANDING',
                'old_values' => ['brand_version' => $latest?->version, 'display_name' => $latest?->display_name, 'tagline' => $latest?->tagline, 'logo_sha256' => $latest?->logo_sha256],
                'new_values' => ['brand_version' => $version->version, 'display_name' => $version->display_name, 'tagline' => $version->tagline, 'logo_sha256' => $version->logo_sha256],
                'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
            ]);

            return ['version' => $version, 'changed' => true];
        });
    }

    private function emptyBaseline(): CompanyBrandVersion
    {
        return new CompanyBrandVersion(['version' => 0, 'reason' => '']);
    }

    /**
     * @param  resource  $stream
     * @return array{blob: FileBlob, media_type: string, width: int, height: int}
     */
    private function storeLogo(User $actor, $stream): array
    {
        $bytes = stream_get_contents($stream, self::MAX_LOGO_BYTES + 1);
        if ($bytes === false || $bytes === '') {
            throw new DomainInvariantException('The logo file is empty or could not be read.', 'logo');
        }
        if (strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw new DomainInvariantException('The logo is larger than 2 MB.', 'logo');
        }
        try {
            $image = ImageSanitiser::sanitise($bytes, CompanyBrandFileOwner::MAX_PIXELS);
            if ($image['width'] * $image['height'] > CompanyBrandFileOwner::MAX_PIXELS) {
                throw new FileException('The logo has more than 1 megapixel (for example 1000 x 1000); use a smaller image.', 'logo', 'image_too_large');
            }
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $image['bytes']);
            rewind($handle);
            $sha = hash('sha256', $image['bytes']);
            $identity = CompanyBrandFileOwner::identity($this->company->id());
            $request = $this->uploads->execute(CompanyBrandFileOwner::OWNER, $identity, CompanyBrandFileOwner::PURPOSE, strlen($image['bytes']), 'brand-logo:'.$actor->id.':'.$sha, $actor->id, $sha);
            $name = $image['media_type'] === ImageSanitiser::PNG ? 'logo.png' : 'logo.jpg';
            $staged = $this->stage->execute($request['intent']->id, $handle, $name, $image['media_type'], $actor->id);
            $verified = $this->verify->execute($request['intent']->id, $actor->id)['blob'];
            if ($verified->health !== 'VERIFIED' || $staged['blob']->id !== $verified->id) {
                throw new FileException('The logo could not be verified.', 'logo', 'logo_unverified');
            }
        } catch (FileException $e) {
            // The person sees the reason in words; the stable code travels as the field's error.
            throw new DomainInvariantException($e->getMessage(), 'logo', $e->reasonCode);
        }

        return ['blob' => $verified, 'media_type' => $image['media_type'], 'width' => $image['width'], 'height' => $image['height']];
    }
}
