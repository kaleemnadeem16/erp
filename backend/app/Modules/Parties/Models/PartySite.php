<?php

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use App\Support\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical location belonging to a Party — shared across roles, e.g. the
 * same site can be both a customer ship-to and a supplier ship-from (see
 * docs/domain/parties.md#shared-sites). Scoped to the current company
 * indirectly, through `party` — see docs/architecture/multi-tenancy.md
 * "Child tables scope through their parent".
 *
 * @property int $party_id
 * @property string $code
 * @property string $name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property Status $status
 */
class PartySite extends Model
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
            'status' => Status::class,
        ];
    }

    /**
     * ISO 3166-1 alpha-2 — no countries table exists yet, see
     * docs/domain/parties.md "Country as a code, not a reference".
     *
     * @return Attribute<string|null, string|null>
     */
    protected function countryCode(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtoupper($value),
        );
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
