<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Services\NumberingPreview;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Governed change of a series' format (ADR 0079 §11): validates the template, scope and continuity against the document type,
 * rejects any configuration whose counters could issue the same number, then adds a new version and closes the previous one.
 * History is never edited; every allocation keeps the version that produced it. Recording an unchanged format writes nothing.
 */
final class SaveSeriesVersion
{
    public function __construct(private readonly NumberingRegistry $registry, private readonly NumberingPreview $preview) {}

    public function __invoke(NumberingSeries $series, string $template, CounterScope $scope, Continuity $continuity, string $reason, User $actor): NumberingSeriesVersion
    {
        $type = $this->registry->get($series->doc_type);
        if (! $actor->can($type->managePermission())) {
            throw new NumberingException("You are not permitted to change {$type->label()} numbering.", 'actor');
        }
        if (trim($reason) === '') {
            throw new NumberingException('Say why the numbering is changing; the reason is kept with the change.', 'reason');
        }
        if ($series->status !== 'ACTIVE') {
            throw new NumberingException('A retired series cannot be changed.', 'series');
        }

        $check = $this->preview->preview($series->doc_type, $template, $scope, $continuity, $series->code);
        if ($check['errors'] !== []) {
            throw new NumberingException(implode(' ', [...$check['errors'], ...$check['collisions']]), 'template');
        }

        return DB::transaction(function () use ($series, $template, $scope, $continuity, $reason, $actor): NumberingSeriesVersion {
            NumberingSeries::query()->whereKey($series->id)->lockForUpdate()->first();
            $current = NumberingSeriesVersion::query()->where('series_id', $series->id)->where('status', 'ACTIVE')->first();
            if ($current !== null && $current->template === $template && $current->scope_period === $scope->period->value
                && ($current->scope_dimensions ?? []) === $scope->dimensions && $current->continuity === $continuity) {
                return $current;
            }
            $now = now();
            $current?->update(['status' => 'SUPERSEDED', 'effective_to' => $now]);

            return NumberingSeriesVersion::query()->create([
                'series_id' => $series->id, 'template' => $template, 'scope_period' => $scope->period->value,
                'scope_dimensions' => $scope->dimensions, 'continuity' => $continuity->value,
                'effective_from' => $now, 'status' => 'ACTIVE', 'reason' => trim($reason), 'changed_by' => $actor->id,
            ]);
        });
    }
}
