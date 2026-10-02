<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A named numbering line of one document type (ADR 0079 §1). It owns its counters; its format lives in versions.
 *
 * @property string $doc_type
 * @property string $code
 * @property string $name
 * @property bool $is_default
 * @property bool $is_selectable
 * @property string $status
 */
class NumberingSeries extends Model
{
    use BelongsToCompany;

    protected $table = 'numbering_series';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_selectable' => 'boolean'];
    }

    /** @return HasMany<NumberingSeriesVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(NumberingSeriesVersion::class, 'series_id');
    }

    /** @return HasOne<NumberingSeriesVersion, $this> */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(NumberingSeriesVersion::class, 'series_id')->where('status', 'ACTIVE');
    }

    /** @return HasMany<NumberingSeriesRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(NumberingSeriesRule::class, 'series_id');
    }
}
