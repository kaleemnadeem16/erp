<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** The working counted composition of a grouped physical count line; replaced by a recount, copied into immutable evidence only at posting. */
class PhysicalCountLineComposition extends Model
{
    use BelongsToCompany;

    protected $table = 'physical_count_line_compositions';

    protected $guarded = ['id'];

    public $timestamps = false;
}
