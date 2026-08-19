<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fixtures;

use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fixture for BelongsToCompanyThroughParentTest — scoped two hops away from
 * company_id, proving the scope composes through a chain rather than only
 * ever a single level.
 */
class ThroughParentTestChild extends Model
{
    use BelongsToCompanyThroughParent;

    protected $table = 'through_parent_test_children';

    protected $guarded = [];

    /**
     * @return BelongsTo<ThroughParentTestParent, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ThroughParentTestParent::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'parent';
    }
}
