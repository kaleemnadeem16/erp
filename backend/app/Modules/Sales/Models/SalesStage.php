<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * A configurable OPEN stage of the pipeline (ADR 0107). Never deleted: opportunities and history refer to it, and history rows keep the stage's name as it was.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property string $status
 */
class SalesStage extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_stages';

    protected $guarded = ['id'];

    public const ACTIVE = 'ACTIVE';

    public const ARCHIVED = 'ARCHIVED';
}
