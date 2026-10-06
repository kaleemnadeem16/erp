<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of a company's own organisation tree (ADR 0089): a site, a department, a warehouse team. What it means is the company's
 * choice; the application only knows it can contain people and own resources.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class OrganisationUnit extends Model
{
    use BelongsToCompany;

    public const ACTIVE = 'ACTIVE';

    public const ARCHIVED = 'ARCHIVED';

    protected $guarded = ['id'];

    /** Codes are the company's own short names and compare case-insensitively, as warehouse codes do. @return Attribute<string, string> */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower($value));
    }

    /** @return BelongsTo<OrganisationUnit, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<OrganisationUnit, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organisation_unit_members', 'unit_id', 'user_id')->withTimestamps();
    }

    /** @return HasMany<OrganisationUnitResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(OrganisationUnitResource::class, 'unit_id');
    }
}
