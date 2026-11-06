<?php

declare(strict_types=1);

namespace App\Modules\Company\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One immutable version of the company's document branding: optional display name, tagline and logo reference, with who made it and why. History: the model and the database
 * refuse an edit or a delete; a change is a new, higher version.
 *
 * @property int $version
 * @property string|null $display_name
 * @property string|null $tagline
 * @property int|null $logo_blob_id
 * @property string|null $logo_sha256
 * @property string|null $logo_media_type
 * @property int|null $logo_width
 * @property int|null $logo_height
 * @property int|null $logo_size_bytes
 * @property string $reason
 * @property int $created_by
 * @property Carbon $created_at
 */
class CompanyBrandVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('A company brand version is history and cannot be edited.'));
        static::deleting(fn () => throw new DomainInvariantException('A company brand version is history and cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function isEmpty(): bool
    {
        return $this->display_name === null && $this->tagline === null && $this->logo_blob_id === null;
    }
}
