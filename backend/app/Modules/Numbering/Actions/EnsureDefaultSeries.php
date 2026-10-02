<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Support\NumberableDocumentType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a document type its DEFAULT series on first use, from the type's registered defaults. A company that has never opened a
 * screen therefore just has numbers; an existing company's series was created by the migration instead.
 */
final class EnsureDefaultSeries
{
    public function __invoke(NumberableDocumentType $type): NumberingSeries
    {
        $existing = NumberingSeries::query()->where('doc_type', $type->key())->where('is_default', true)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($type): NumberingSeries {
                $series = NumberingSeries::query()->create(['doc_type' => $type->key(), 'code' => 'DEFAULT', 'name' => 'Default', 'is_default' => true, 'is_selectable' => false, 'status' => 'ACTIVE']);
                NumberingSeriesVersion::query()->create([
                    'series_id' => $series->id,
                    'template' => $type->defaultTemplate(),
                    'scope_period' => $type->defaultScope()->period->value,
                    'scope_dimensions' => $type->defaultScope()->dimensions,
                    'continuity' => $type->defaultContinuity()->value,
                    'effective_from' => now(),
                    'status' => 'ACTIVE',
                    'reason' => 'Created from the document type\'s defaults on first use.',
                ]);

                return $series;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created it first; use theirs.
            return NumberingSeries::query()->where('doc_type', $type->key())->where('is_default', true)->firstOrFail();
        }
    }
}
