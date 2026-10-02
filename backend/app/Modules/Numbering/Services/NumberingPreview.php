<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterKey;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\NumberRenderer;
use App\Modules\Numbering\Support\ParsedTemplate;
use App\Modules\Numbering\Support\ScopePeriod;
use App\Modules\Numbering\Support\TemplateParser;
use App\Modules\Numbering\Support\VariableCatalog;
use Carbon\CarbonImmutable;

/**
 * The tester (ADR 0079 §15): explains a configuration — the rendered example, which instant and time zone, the counter scope
 * and when it resets, the continuity, the next numbers, the first number of the next period, collisions and format problems, and
 * what changing from the series' current format would mean. It reads counters without taking a number (it never calls allocate
 * and never advances a sequence).
 */
final class NumberingPreview
{
    public function __construct(
        private readonly NumberingRegistry $registry,
        private readonly TemplateValidator $validator,
        private readonly TemplateParser $parser,
        private readonly VariableResolver $resolver,
        private readonly ConstantsResolver $constants,
        private readonly CompanyClock $clock,
        private readonly FiscalCalendar $fiscal,
        private readonly CounterStore $counters,
        private readonly NumberRenderer $renderer,
        private readonly UniquenessChecker $uniqueness,
    ) {}

    /**
     * @param  string|null  $seriesCode  an existing series to read live counters and compare against; null for a brand-new one
     * @return array<string, mixed>
     */
    public function preview(string $docType, string $template, CounterScope $scope, Continuity $continuity, ?string $seriesCode = null, ?CarbonImmutable $at = null): array
    {
        $type = $this->registry->get($docType);
        $at ??= CarbonImmutable::now();
        $series = $seriesCode !== null ? NumberingSeries::query()->where('doc_type', $docType)->where('code', $seriesCode)->first() : null;
        $current = $series !== null ? NumberingSeriesVersion::query()->where('series_id', $series->id)->where('status', 'ACTIVE')->first() : null;

        $checked = $this->validator->validate($type, $template, $scope);
        $errors = $checked['errors'];
        $parsed = $checked['template'];
        $zone = $this->clock->timezone();
        $usesDate = $parsed !== null && ($scope->needsDate() || collect($parsed->variables())->contains(fn (array $v): bool => VariableCatalog::needsDate($v['key'])));

        $result = [
            'document_type' => $type->key(), 'document_label' => $type->label(), 'template' => $template, 'series' => $seriesCode ?? 'NEW',
            'official_when' => $type->becomesOfficial(),
            'date_basis' => 'The moment the document becomes official ('.$type->becomesOfficial().'), not its order or document date.',
            'timezone' => $zone, 'issued_at' => $at->toIso8601String(),
            'counter_scope' => $scope->describe(), 'reset' => $this->resetText($scope),
            'continuity' => $continuity->value, 'continuity_text' => $continuity->label(),
            'continuity_locked' => $type->continuityLocked(),
            'length' => $checked['maxLength'], 'max_length' => TemplateParser::MAX_TOTAL_LENGTH,
            'errors' => $errors, 'warnings' => $usesDate && $this->clock->isDefault() ? ['The company time zone is UTC, the default. If your business day follows another time zone, set it first: an order issued after midnight local time may otherwise be numbered into the previous day or month.'] : [], 'collisions' => [],
            'example' => null, 'next_numbers' => [], 'first_of_next_period' => null, 'change_impact' => null,
        ];
        if ($type->continuityLocked() && $continuity !== $type->defaultContinuity()) {
            $result['errors'][] = "{$type->label()} numbers must stay {$type->defaultContinuity()->value}; that policy is locked for this document type.";
        }
        if ($parsed === null || $errors !== []) {
            return $result;
        }

        $constants = $this->constants->active();
        $local = $this->local($scope, $parsed, $at);
        $fiscalPosition = $local !== null ? $this->fiscal->forDate($local->startOfDay()) : null;
        $context = $type->sampleContext($at);
        $dimensionValues = [];
        foreach ($scope->dimensions as $dimension) {
            $dimensionValues[$dimension] = $context->values[$dimension]->value ?? 'S1';
        }

        try {
            $resolved = $this->resolver->resolve($parsed, $context, $local, $fiscalPosition, $constants);
        } catch (NumberingException $e) {
            $result['errors'][] = $e->getMessage();

            return $result;
        }
        $texts = array_map(fn (array $r): string => $r['value'], $resolved);
        $key = CounterKey::build($scope, $local, $fiscalPosition, $dimensionValues);
        $counter = $series !== null ? $this->counters->find($series, $key) : null;
        $next = $counter !== null ? $this->counters->strategy($counter)->peek($counter) : 1;

        $result['local_date'] = $local?->toDateString();
        $result['counter_key'] = $key;
        $result['counter_next'] = $next;
        $result['example'] = $this->renderer->render($parsed, $texts, $next);
        $result['next_numbers'] = array_map(fn (int $n): string => $this->renderer->render($parsed, $texts, $n), [$next, $next + 1, $next + 2]);
        $result['first_of_next_period'] = $this->firstOfNextPeriod($type, $parsed, $scope, $at, $constants);

        $others = [];
        foreach (NumberingSeries::query()->where('doc_type', $docType)->where('status', 'ACTIVE')->get() as $other) {
            $version = NumberingSeriesVersion::query()->where('series_id', $other->id)->where('status', 'ACTIVE')->first();
            if ($version !== null && $other->code !== $seriesCode) {
                $others[] = ['code' => $other->code, 'template' => $this->parser->parse($version->template), 'scope' => $version->scope()];
            }
        }
        $found = $this->uniqueness->check($type, [['code' => $seriesCode ?? 'NEW', 'template' => $parsed, 'scope' => $scope], ...$others], $at);
        $result['collisions'] = $found['collisions'];
        $result['warnings'] = [...$result['warnings'], ...$found['warnings']];
        if ($found['collisions'] !== []) {
            $result['errors'][] = 'Two counters could issue the same number; the format cannot be saved as it is.';
        }
        $result['change_impact'] = $this->impact($current, $template, $scope, $continuity);

        return $result;
    }

    private function local(CounterScope $scope, ParsedTemplate $parsed, CarbonImmutable $at): ?CarbonImmutable
    {
        $usesDate = $scope->needsDate() || collect($parsed->variables())->contains(fn (array $v): bool => VariableCatalog::needsDate($v['key']));

        return $usesDate ? $this->clock->local($at) : null;
    }

    private function resetText(CounterScope $scope): string
    {
        return match ($scope->period) {
            ScopePeriod::None => 'The counter never restarts.',
            ScopePeriod::Year => 'Restarts at 1 on the first number issued in each calendar year (by the issue date).',
            ScopePeriod::Month => 'Restarts at 1 on the first number issued in each calendar month (by the issue date).',
            ScopePeriod::Day => 'Restarts at 1 on the first number issued each day (by the issue date).',
            ScopePeriod::FiscalYear => 'Restarts at 1 on the first number issued in each fiscal year.',
            ScopePeriod::FiscalPeriod => 'Restarts at 1 on the first number issued in each fiscal period.',
        };
    }

    /** @param  array<string, string>  $constants */
    private function firstOfNextPeriod(NumberableDocumentType $type, ParsedTemplate $parsed, CounterScope $scope, CarbonImmutable $at, array $constants): ?string
    {
        $later = match ($scope->period) {
            ScopePeriod::None => null,
            ScopePeriod::Year, ScopePeriod::FiscalYear => $at->addYear(),
            ScopePeriod::Month, ScopePeriod::FiscalPeriod => $at->addMonth(),
            ScopePeriod::Day => $at->addDay(),
        };
        if ($later === null) {
            return null;
        }
        $local = $this->clock->local($later);
        $fiscal = $this->fiscal->forDate($local->startOfDay());
        try {
            $resolved = $this->resolver->resolve($parsed, $type->sampleContext($later), $local, $fiscal, $constants);
        } catch (NumberingException) {
            return null;
        }

        return $this->renderer->render($parsed, array_map(fn (array $r): string => $r['value'], $resolved), 1);
    }

    private function impact(?NumberingSeriesVersion $current, string $template, CounterScope $scope, Continuity $continuity): string
    {
        $base = 'Applies to numbers issued from now on; numbers already issued do not change.';
        if ($current === null) {
            return 'A new series. '.$base;
        }
        $changes = [];
        if ($current->template !== $template) {
            $changes[] = "the format changes from {$current->template}";
        }
        if ($current->scope()->describe() !== $scope->describe() || $current->scope_period !== $scope->period->value) {
            $changes[] = 'the counter scope changes, so new counters start for the new scope';
        }
        if ($current->continuity !== $continuity) {
            $changes[] = "the policy changes from {$current->continuity->value} to {$continuity->value}";
        }

        return $changes === [] ? 'No change from the current format.' : ucfirst(implode('; ', $changes)).'. '.$base;
    }
}
