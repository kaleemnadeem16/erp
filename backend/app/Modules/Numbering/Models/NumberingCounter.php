<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * The running number for one (series, counter key). LOCKED counters keep next_number and are advanced under a row lock;
 * SEQUENCE counters own a PostgreSQL sequence (ADR 0079 §9).
 *
 * @property int $series_id
 * @property string $counter_key
 * @property string $allocator
 * @property int|null $next_number
 * @property string|null $sequence_name
 */
class NumberingCounter extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
