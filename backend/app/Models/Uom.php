<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\UomDimension;

/**
 * A company-defined unit of measure. Conversion between two Uoms is only valid
 * within the same dimension — see App\Support\UomConversionService and
 * docs/domain/units-of-measure.md.
 *
 * @property string $code
 * @property UomDimension $dimension
 * @property string $factor_to_base
 * @property bool $is_base
 */
class Uom extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension' => UomDimension::class,
            'factor_to_base' => 'decimal:9',
            'is_base' => 'bool',
        ];
    }
}
