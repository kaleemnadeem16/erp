<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

/**
 * A company's approval mode for one subject type (ADR 0070 §2). No row means NONE.
 *
 * @property string $subject_type
 * @property ApprovalMode $mode
 */
class ApprovalSubjectSetting extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['changed_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => ApprovalMode::class,
        ];
    }
}
