<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Imports\Support\ImportRowStatus;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ImportRowStatus $status
 * @property array<string, mixed> $raw_data
 * @property array<string, mixed> $mapped_data
 * @property array<string, array<int, string>>|null $errors
 */
class ImportBatchRow extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ImportBatchRow $row): void {
            if (array_diff(array_keys($row->getDirty()), ['status', 'errors', 'updated_at']) !== []) {
                throw new DomainInvariantException('Staged import source and mapped data are immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Staged import rows cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'mapped_data' => 'array', 'errors' => 'array', 'status' => ImportRowStatus::class];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    protected static function companyScopeRelation(): string
    {
        return 'batch';
    }
}
