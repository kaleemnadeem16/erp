<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * Immutable service handover preparation; no target creation or release.
 *
 * @property int $stage_id
 * @property int $issued_line_id
 * @property int $version
 * @property int|null $contact_id
 * @property int|null $site_id
 * @property array<string,mixed> $operational_snapshot
 */
class HandoverBaseline extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_baselines';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['operational_snapshot' => 'array'];
    }
}
