<?php

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * One control of a published set: an opaque reference, a kind, a mode and its limit.
 *
 * @property int $id
 * @property int $control_set_id
 * @property string $control_ref
 * @property int $control_version
 * @property string $kind
 * @property string $mode
 * @property string $limit_value
 * @property int|null $currency_id
 * @property string $basis
 */
class QuotationNetControl extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_net_controls';

    protected $guarded = ['id'];
}
