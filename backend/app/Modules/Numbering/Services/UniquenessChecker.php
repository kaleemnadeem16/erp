<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Support\CounterKey;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Numbering\Support\NumberRenderer;
use App\Modules\Numbering\Support\ParsedTemplate;
use App\Modules\Numbering\Support\VariableCatalog;
use Carbon\CarbonImmutable;

/**
 * Uniqueness is the real invariant (ADR 0079 §7): a number must be unique within (company, document type), across every series.
 * This checks the *configuration*: it enumerates the numbers a series can produce — across a few periods, the dimension values
 * that exist today (plus stand-ins), and a spread of counter values — and reports two different counters, in one series or
 * in two, that produce the same text. Sampling is a guard, not a proof; the unique index on the allocation log is the proof.
 */
final class UniquenessChecker
{
    private const MAX_COMBINATIONS = 16;

    public function __construct(
        private readonly VariableResolver $resolver,
        private readonly ConstantsResolver $constants,
        private readonly CompanyClock $clock,
        private readonly FiscalCalendar $fiscal,
        private readonly NumberRenderer $renderer,
    ) {}

    /**
     * @param  list<array{code: string, template: ParsedTemplate, scope: CounterScope}>  $series  the candidate first, then the other active series of the type
     * @return array{collisions: list<string>, warnings: list<string>}
     */
    public function check(NumberableDocumentType $type, array $series, CarbonImmutable $now): array
    {
        $seen = [];
        $collisions = [];
        foreach ($series as $one) {
            foreach ($this->enumerate($type, $one['template'], $one['scope'], $now) as [$number, $origin]) {
                $label = $one['code'].' / '.$origin;
                if (isset($seen[$number]) && $seen[$number] !== $label) {
                    $collisions[$number.$seen[$number].$label] = "{$seen[$number]} and {$label} both produce {$number}.";
                } else {
                    $seen[$number] = $label;
                }
            }
        }

        $warnings = [];
        $candidate = $series[0];
        foreach (array_slice($series, 1) as $other) {
            $a = $candidate['template']->literalPrefix();
            $b = $other['template']->literalPrefix();
            if ($other['code'] !== $candidate['code'] && ($a === '' || $b === '' || str_starts_with($a, $b) || str_starts_with($b, $a))) {
                $warnings[] = "The series {$other['code']} starts with similar fixed text ('{$b}' and '{$a}'), so the two could produce the same numbers in a way the sample did not show.";
            }
        }

        return ['collisions' => array_values($collisions), 'warnings' => $warnings];
    }

    /** @return list<array{0: string, 1: string}> [number, "counterKey"] */
    private function enumerate(NumberableDocumentType $type, ParsedTemplate $template, CounterScope $scope, CarbonImmutable $now): array
    {
        $out = [];
        $constants = $this->constants->active();
        $zone = $this->clock->timezone();
        $usesDate = $scope->needsDate() || collect($template->variables())->contains(fn (array $v): bool => VariableCatalog::needsDate($v['key']));
        $instants = $usesDate ? [$now, $now->addMonth(), $now->addYear(), $now->subYear()] : [$now];
        $known = $type->knownDimensionValues();
        $width = $template->seqWidth();
        $counters = array_values(array_unique([1, 2, 9, 10, 99, 100, 999, 1000, 9999, 10000, 99999, 100000, (10 ** $width) - 1, 10 ** $width]));

        foreach ($instants as $instant) {
            $local = $usesDate ? $instant->setTimezone($zone) : null;
            $fiscal = $local !== null ? $this->fiscal->forDate($local->startOfDay()) : null;
            if ($scope->period->needsFiscalCalendar() && $fiscal === null) {
                continue;
            }
            foreach ($this->combinations($scope, $known) as $dimensionValues) {
                $context = $type->sampleContext($instant);
                foreach ($dimensionValues as $dimension => $value) {
                    $context = $context->withValue($dimension, new NumberingValue($value));
                }
                try {
                    $resolved = array_map(fn (array $r): string => $r['value'], $this->resolver->resolve($template, $context, $local, $fiscal, $constants));
                } catch (NumberingException) {
                    continue;
                }
                $key = CounterKey::build($scope, $local, $fiscal, $dimensionValues);
                foreach ($counters as $counter) {
                    $out[] = [$this->renderer->render($template, $resolved, $counter), $key];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $known
     * @return list<array<string, string>> every combination of dimension values (the ones that exist, topped up to two stand-ins)
     */
    private function combinations(CounterScope $scope, array $known): array
    {
        $combos = [[]];
        foreach ($scope->dimensions as $dimension) {
            $values = array_slice($known[$dimension] ?? [], 0, 4);
            foreach (['S1', 'S2'] as $standIn) {
                if (count($values) < 2) {
                    $values[] = $standIn;
                }
            }
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$dimension => $value];
                }
            }
            $combos = array_slice($next, 0, self::MAX_COMBINATIONS);
        }

        return $combos;
    }
}
