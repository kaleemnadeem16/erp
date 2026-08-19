<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fixtures;

use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fixture for BelongsToCompanyThroughParentTest — scoped one hop away from
 * company_id, through ThroughParentTestGrandparent.
 */
class ThroughParentTestParent extends Model
{
    use BelongsToCompanyThroughParent;

    protected $table = 'through_parent_test_parents';

    protected $guarded = [];

    /**
     * @return BelongsTo<ThroughParentTestGrandparent, $this>
     */
    public function grandparent(): BelongsTo
    {
        return $this->belongsTo(ThroughParentTestGrandparent::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'grandparent';
    }
}
