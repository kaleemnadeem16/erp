<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * The exact old and new value of one corrected enquiry detail. Append-only.
 *
 * @property int $id
 * @property int $correction_id
 * @property string $field_name
 * @property string|null $old_value
 * @property string|null $new_value
 */
class LeadEnquiryCorrectionField extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_lead_enquiry_correction_fields';

    protected $guarded = ['id'];
}
