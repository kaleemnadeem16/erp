<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Modules\Estimating\Support\FormulationVersionStatus;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One recipe revision. DRAFT is a scratch pad — its lines and notes may be
 * freely replaced. ACTIVE is the one currently live for its formulation.
 * SUPERSEDED is historical and kept forever — see
 * docs/domain/formulations.md "Versioning and immutability". Scoped through
 * formulation, not its own company_id.
 *
 * @property int $formulation_id
 * @property int $version_no
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property FormulationVersionStatus $status
 * @property string|null $notes
 * @property-read Formulation $formulation
 */
class FormulationVersion extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FormulationVersionStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Formulation, $this> */
    public function formulation(): BelongsTo
    {
        return $this->belongsTo(Formulation::class);
    }

    /** @return HasMany<FormulationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FormulationLine::class)->orderBy('sequence');
    }

    protected static function companyScopeRelation(): string
    {
        return 'formulation';
    }
}
