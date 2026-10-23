<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** Immutable Tax evidence. Economic edits create successors, never rewrite a row. */
final class TaxDependencyVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [];
}
