<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** Immutable package-kernel evidence. Not an enabled on-hand balance or public write boundary. */
class PackageOperation extends Model
{
    /** The evidence kinds that form a scope's chain: each is a complete successor of the one before it, and the latest of them is the scope's current state. */
    public const CHAIN = ['COUNTED', 'OPEN', 'UNPACK', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'RECEIPT_IN', 'COUNT_VARIANCE', 'RECEIPT_REVERSAL', 'QA_DISPOSITION', 'REJECTED_EXIT'];

    use BelongsToCompany;

    protected $table = 'inventory_package_operations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Package kernel evidence is immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Package kernel evidence is immutable.'));
    }
}
