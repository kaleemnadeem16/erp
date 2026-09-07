<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property InventoryCutoverBatchStatus $status
 * @property int $inventory_cutover_id
 * @property int $import_batch_id
 * @property string $entity_type
 * @property int $source_record_count
 * @property int $accepted_record_count
 * @property int $rejected_record_count
 * @property string $source_quantity
 * @property string $source_value
 * @property string $base_source_value
 */
class InventoryCutoverBatch extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (InventoryCutoverBatch $batch): void {
            if (array_diff(array_keys($batch->getDirty()), ['status', 'updated_at']) !== []) {
                throw new DomainInvariantException('Inventory cutover batch facts are immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Inventory cutover batches cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['inventory_cutover_id' => InventoryCutover::class, 'import_batch_id' => ImportBatch::class];
    }

    protected function casts(): array
    {
        return [
            'status' => InventoryCutoverBatchStatus::class,
            'source_quantity' => 'decimal:4', 'source_value' => 'decimal:4', 'base_source_value' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<InventoryCutover, $this> */
    public function cutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class, 'inventory_cutover_id');
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /** @return HasMany<InventorySourceFact, $this> */
    public function sourceFacts(): HasMany
    {
        return $this->hasMany(InventorySourceFact::class);
    }
}
