<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * FULL_ONLY customer acceptance storage. Inactive until the owner approves duty holders.
 *
 * @property int $version
 * @property string $policy_code
 * @property array<string, mixed> $source_requirements
 */
class CustomerEvidencePolicyVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_evidence_policy_versions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source_requirements' => 'array'];
    }
}
