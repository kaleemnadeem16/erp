<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportProfile extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** @return HasMany<ImportMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    /** @return HasMany<ImportBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }
}
