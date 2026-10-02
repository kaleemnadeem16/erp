<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingAllocation;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Support\AllocatedNumber;
use App\Modules\Numbering\Support\CounterKey;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\NumberRenderer;
use App\Modules\Numbering\Support\TemplateParser;
use App\Modules\Numbering\Support\VariableCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one entry point: a document that has just become official hands over its numbering context and receives its number.
 *
 * The engine resolves registered variables, selects a series, finds the counter for the series' explicit scope, takes the
 * next number with the strategy the series' continuity calls for, renders it, and writes the frozen allocation record — all in
 * the caller's transaction, so a failure anywhere leaves no number behind (a GAPLESS counter returns it; an ALLOW_GAPS one
 * may leave a gap). It knows nothing about the documents themselves.
 */
final class NumberingService
{
    public function __construct(
        private readonly NumberingRegistry $registry,
        private readonly SeriesRouter $router,
        private readonly TemplateParser $parser,
        private readonly VariableResolver $resolver,
        private readonly ConstantsResolver $constants,
        private readonly CompanyClock $clock,
        private readonly FiscalCalendar $fiscal,
        private readonly CounterStore $counters,
        private readonly NumberRenderer $renderer,
    ) {}

    /** @throws NumberingException */
    public function allocate(NumberingContext $context): AllocatedNumber
    {
        $type = $this->registry->get($context->documentType);

        return DB::transaction(function () use ($type, $context): AllocatedNumber {
            $series = $this->router->choose($type, $context);
            $version = NumberingSeriesVersion::query()->where('series_id', $series->id)->where('status', 'ACTIVE')->first()
                ?? throw new NumberingException("The series '{$series->code}' has no active format.", 'series');
            $parsed = $this->parser->parse($version->template);
            $scope = $version->scope();

            $usesDate = $scope->needsDate() || collect($parsed->variables())->contains(fn (array $v): bool => VariableCatalog::needsDate($v['key']));
            $local = $usesDate ? $this->clock->local($context->issuedAt) : null;
            $needsFiscal = $scope->period->needsFiscalCalendar() || collect($parsed->variables())->contains(fn (array $v): bool => VariableCatalog::needsFiscalCalendar($v['key']));
            $fiscal = $needsFiscal && $local !== null ? $this->fiscal->forDate($local->startOfDay()) : null;
            if ($scope->period->needsFiscalCalendar() && $fiscal === null) {
                throw new NumberingException('No fiscal year covers the date this document became official, so its counter cannot be chosen. Set up the fiscal calendar first.', 'fiscal');
            }

            $resolved = $this->resolver->resolve($parsed, $context, $local, $fiscal, $this->constants->active());

            $dimensionValues = [];
            foreach ($scope->dimensions as $dimension) {
                $supplied = $context->values[$dimension] ?? throw new NumberingException("The document did not supply {{$dimension}}, which this series counts by.", $dimension);
                $dimensionValues[$dimension] = $supplied->value;
                $resolved[$dimension] ??= ['value' => $supplied->value, 'source' => $supplied->source];
            }
            $key = CounterKey::build($scope, $local, $fiscal, $dimensionValues);

            $counter = $this->counters->forAllocation($series, $key, $version->continuity);
            $value = $this->counters->strategy($counter)->allocate($counter);
            $number = $this->renderer->render($parsed, array_map(fn (array $r): string => $r['value'], $resolved), $value);

            if (strlen($number) > TemplateParser::MAX_TOTAL_LENGTH) {
                throw new NumberingException("The number {$number} is longer than the ".TemplateParser::MAX_TOTAL_LENGTH.' characters allowed. Shorten the format or the values it uses.', 'number');
            }
            try {
                $allocation = DB::transaction(fn () => NumberingAllocation::query()->create([
                    'doc_type' => $type->key(), 'series_id' => $series->id, 'version_id' => $version->id, 'document_id' => $context->documentId,
                    'number' => $number, 'counter_key' => $key, 'counter_value' => $value,
                    'issued_at' => $context->issuedAt, 'local_date' => $local?->toDateString(),
                    'variables' => $resolved, 'allocated_by' => $context->allocatedBy,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new NumberingException("The number {$number} has already been issued for {$type->label()}. Two series or counters can produce the same number; change the format so they cannot.", 'number');
            }

            return new AllocatedNumber($number, $allocation->id, $series->code, $version->id, $key, $value, $resolved);
        });
    }
}
