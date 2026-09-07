<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMapping extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transform' => 'array'];
    }

    /** @return BelongsTo<ImportProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class, 'import_profile_id');
    }

    protected static function companyScopeRelation(): string
    {
        return 'profile';
    }
}
