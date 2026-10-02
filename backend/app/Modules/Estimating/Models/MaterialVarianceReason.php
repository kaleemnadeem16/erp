<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A company-defined reason a planned material quantity differs from the calculated standard.
 * Company data, not a fixed list: nothing is seeded outside demo data.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $active
 */
class MaterialVarianceReason extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'bool'];
    }

    /** @return Attribute<string, string> */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }
}
