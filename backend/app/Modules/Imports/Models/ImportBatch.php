<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Imports\Support\ImportBatchStatus;
use App\Modules\Imports\Support\ImportPurpose;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ImportPurpose $purpose
 * @property ImportBatchStatus $status
 * @property string $entity_type
 * @property string $external_batch_id
 * @property string $source_sha256
 * @property int $import_profile_id
 */
class ImportBatch extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ImportBatch $batch): void {
            $allowed = ['status', 'validated_at', 'updated_at'];
            if (array_diff(array_keys($batch->getDirty()), $allowed) !== []) {
                throw new DomainInvariantException('Import batch source facts are immutable after staging.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Import batches cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['import_profile_id' => ImportProfile::class, 'uploaded_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'purpose' => ImportPurpose::class,
            'status' => ImportBatchStatus::class,
            'uploaded_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ImportProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class);
    }

    /** @return HasMany<ImportBatchRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }
}
