<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * FULL_ONLY customer acceptance storage. Inactive until the owner approves duty holders.
 *
 * @property int $acceptance_id
 * @property int $issued_line_id
 */
class CustomerAcceptanceLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_acceptance_lines';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
