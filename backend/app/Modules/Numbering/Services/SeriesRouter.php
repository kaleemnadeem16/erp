<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Actions\EnsureDefaultSeries;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesRule;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingException;

/**
 * Chooses the series a document is numbered from (ADR 0079 §10): a manual choice (only a selectable series; the caller has already
 * checked the actor's permission), else the first matching routing rule by priority, else the default series.
 */
final class SeriesRouter
{
    public function __construct(private readonly EnsureDefaultSeries $ensureDefault) {}

    /** @throws NumberingException */
    public function choose(NumberableDocumentType $type, NumberingContext $context): NumberingSeries
    {
        if ($context->requestedSeries !== null) {
            $series = NumberingSeries::query()->where('doc_type', $type->key())->where('code', $context->requestedSeries)->first();
            if ($series === null || $series->status !== 'ACTIVE') {
                throw new NumberingException("There is no active series '{$context->requestedSeries}' for {$type->label()}.", 'series');
            }
            if (! $series->is_selectable && ! $series->is_default) {
                throw new NumberingException("The series '{$series->code}' cannot be chosen by hand.", 'series');
            }

            return $series;
        }

        $rules = NumberingSeriesRule::query()
            ->where('status', 'ACTIVE')
            ->whereIn('series_id', NumberingSeries::query()->where('doc_type', $type->key())->where('status', 'ACTIVE')->select('id'))
            ->orderBy('priority')->orderBy('id')->get();
        foreach ($rules as $rule) {
            if ($rule->matches($context->routing)) {
                return NumberingSeries::query()->findOrFail($rule->series_id);
            }
        }

        return ($this->ensureDefault)($type);
    }
}
