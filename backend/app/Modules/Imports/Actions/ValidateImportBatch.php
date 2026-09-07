<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportBatchStatus;
use App\Modules\Imports\Support\ImportRowStatus;
use App\Modules\Imports\Support\ImportTargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ValidateImportBatch
{
    public function __construct(private readonly ImportTargetRegistry $targets) {}

    public function __invoke(ImportBatch $batch): ImportBatch
    {
        return DB::transaction(function () use ($batch): ImportBatch {
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status === ImportBatchStatus::Validated) {
                return $batch->load('rows');
            }
            if ($batch->status !== ImportBatchStatus::Staged) {
                throw new DomainInvariantException('Only a staged import batch can be validated.', 'status');
            }
            $target = $this->targets->get($batch->purpose, $batch->entity_type);
            foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
                $validator = Validator::make($row->mapped_data, $target->rules());
                $errors = $validator->errors()->toArray();
                $row->update([
                    'status' => $errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Invalid,
                    'errors' => $errors === [] ? null : $errors,
                ]);
            }
            $batch->update(['status' => ImportBatchStatus::Validated, 'validated_at' => now()]);

            return $batch->load('rows');
        });
    }
}
