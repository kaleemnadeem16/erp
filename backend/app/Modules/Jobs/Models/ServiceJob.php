<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The domain Job (the Laravel queue's `jobs` table is unrelated). SP0b creates no real Job: rows exist only as test fixtures.
 *
 * @property int $id
 * @property string $code
 * @property string $state
 * @property int $generation
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServiceJob extends Model
{
    use BelongsToCompany;

    protected $table = 'service_jobs';

    protected $guarded = ['id'];
}
