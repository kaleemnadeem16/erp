<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Imports\Support\ImportTargetRegistry;
use Illuminate\Support\Facades\DB;

final class StageImportBatch
{
    public function __construct(private readonly ImportTargetRegistry $targets) {}

    /** @param array<int, array<string, mixed>> $rows */
    public function __invoke(ImportProfile $profile, ImportPurpose $purpose, string $externalBatchId, string $fileName, array $rows, User $actor): ImportBatch
    {
        $profile = ImportProfile::query()->findOrFail($profile->id);
        $rows = array_values($rows);
        $this->targets->get($purpose, $profile->entity_type);
        if ($rows === [] || trim($externalBatchId) === '' || trim($fileName) === '') {
            throw new DomainInvariantException('A source identity, file name and at least one row are required.');
        }
        $profile->loadMissing('mappings');
        $mappedRows = array_map(function (array $row) use ($profile): array {
            $mapped = [];
            /** @var array<int, array<string, mixed>> $appliedTransformations */
            $appliedTransformations = [];
            foreach ($profile->mappings as $mapping) {
                $value = $row[$mapping->source_column] ?? null;
                $transformation = $mapping->transform;
                if (is_array($transformation)
                    && ($transformation['type'] ?? null) === 'DEFAULT_IF_MISSING'
                    && ($value === null || (is_string($value) && trim($value) === ''))) {
                    $value = $transformation['value'];
                    $appliedTransformations[] = [
                        'source_column' => $mapping->source_column,
                        'target_field' => $mapping->target_field,
                        ...$transformation,
                    ];
                }
                $mapped[$mapping->target_field] = $value;
            }
            if ($appliedTransformations !== []) {
                $mapped['_transformations'] = $appliedTransformations;
            }

            return $mapped;
        }, $rows);
        $checksum = hash('sha256', json_encode([$profile->id, $fileName, $rows], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($profile, $purpose, $externalBatchId, $fileName, $rows, $mappedRows, $actor, $checksum): ImportBatch {
            $existing = ImportBatch::query()->where('purpose', $purpose)->where('external_batch_id', $externalBatchId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->source_sha256 !== $checksum || $existing->import_profile_id !== $profile->id) {
                    throw new DomainInvariantException('This external batch identity already belongs to different source data.', 'external_batch_id');
                }

                return $existing->load('rows');
            }

            $batch = ImportBatch::query()->create([
                'import_profile_id' => $profile->id, 'purpose' => $purpose,
                'entity_type' => $profile->entity_type, 'external_batch_id' => trim($externalBatchId),
                'source_sha256' => $checksum, 'file_name' => trim($fileName),
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            ]);
            foreach ($mappedRows as $index => $mapped) {
                $batch->rows()->create(['row_number' => $index + 1, 'raw_data' => $rows[$index], 'mapped_data' => $mapped]);
            }

            return $batch->load('rows');
        });
    }
}
