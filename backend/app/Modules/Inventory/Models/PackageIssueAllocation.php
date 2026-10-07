<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** Immutable package-kernel evidence. Not an enabled on-hand balance or public write boundary. */
class PackageIssueAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_package_issue_allocations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Package kernel evidence is immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Package kernel evidence is immutable.'));
    }
}
