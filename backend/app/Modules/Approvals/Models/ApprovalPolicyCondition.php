<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Modules\Approvals\Support\ApprovalOperator;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

/**
 * @property int $policy_id
 * @property string $fact
 * @property ApprovalOperator $operator
 * @property mixed $value a scalar, or a list for IN
 */
class ApprovalPolicyCondition extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operator' => ApprovalOperator::class,
            'value' => 'json',
        ];
    }
}
