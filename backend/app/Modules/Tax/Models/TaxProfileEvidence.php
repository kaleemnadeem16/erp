<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Spatie\Activitylog\Support\LogOptions;

/** Protected evidence has no public projection, including generic audit and nested serialization. */
abstract class TaxProfileEvidence extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly([])->dontLogEmptyChanges();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }
}
