<?php

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One published, immutable version of a company's final-net controls (D3). The highest version of a company is the one in force.
 *
 * @property int $id
 * @property int $version_no
 * @property string|null $rounding_policy_fingerprint
 * @property string $reason
 * @property int $published_by
 */
class QuotationControlSet extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_control_sets';

    protected $guarded = ['id'];

    /** @return HasMany<QuotationNetControl, $this> */
    public function controls(): HasMany
    {
        return $this->hasMany(QuotationNetControl::class, 'control_set_id')->orderBy('control_ref');
    }
}
