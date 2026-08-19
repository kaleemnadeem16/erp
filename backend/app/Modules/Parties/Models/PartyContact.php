<?php

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contact person at a Party. Scoped to the current company indirectly,
 * through `party` — see docs/architecture/multi-tenancy.md "Child tables
 * scope through their parent".
 *
 * @property int $party_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $title
 * @property bool $is_primary
 */
class PartyContact extends Model
{
    use BelongsToCompanyThroughParent;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'party';
    }
}
