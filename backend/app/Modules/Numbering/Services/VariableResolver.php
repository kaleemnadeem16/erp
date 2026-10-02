<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Support\FiscalPosition;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\ParsedTemplate;
use App\Modules\Numbering\Support\TemplateParser;
use App\Modules\Numbering\Support\VariableCatalog;
use Carbon\CarbonImmutable;

/** Turns a parsed template's variables into text for one numbering context, recording where each value came from. */
final class VariableResolver
{
    /**
     * @param  array<string, string>  $constants
     * @return array<string, array{value: string, source: string|null}> variable token (KEY or KEY:option) => value and source
     */
    public function resolve(ParsedTemplate $template, NumberingContext $context, ?CarbonImmutable $local, ?FiscalPosition $fiscal, array $constants): array
    {
        $out = [];
        foreach ($template->variables() as ['key' => $key, 'option' => $option]) {
            $token = $key.($option !== null ? ':'.$option : '');
            if (isset($out[$token])) {
                continue;
            }
            if (VariableCatalog::isEngine($key)) {
                $out[$token] = ['value' => $this->engine($key, $option, $local, $fiscal), 'source' => null];
            } elseif (isset($constants[$key])) {
                $out[$token] = ['value' => $constants[$key], 'source' => 'constant:'.$key];
            } elseif (isset($context->values[$key])) {
                $value = $context->values[$key];
                if (! preg_match('/^['.TemplateParser::LITERAL_CHARACTERS.']{1,'.TemplateValidator::MAX_VALUE_LENGTH.'}$/', $value->value)) {
                    throw new NumberingException("The value '{$value->value}' for {{$key}} cannot be used in a number (letters, digits and - _ / . only, up to ".TemplateValidator::MAX_VALUE_LENGTH.' characters).', $key);
                }
                $out[$token] = ['value' => $value->value, 'source' => $value->source];
            } else {
                throw new NumberingException("The document did not supply {{$key}}, which this number format needs.", $key);
            }
        }

        return $out;
    }

    private function engine(string $key, ?string $option, ?CarbonImmutable $local, ?FiscalPosition $fiscal): string
    {
        if (in_array($key, ['FY', 'FP'], true)) {
            if ($fiscal === null) {
                throw new NumberingException('No fiscal year covers the date this document became official, so a fiscal year or period cannot be put in its number. Set up the fiscal calendar first.', $key);
            }
            if ($key === 'FP') {
                return str_pad((string) $fiscal->periodNumber, 2, '0', STR_PAD_LEFT);
            }
            $start = (int) $fiscal->yearStart->format('Y');
            $end = (int) $fiscal->yearEnd->format('Y');

            return match (true) {
                $option === 'START' => (string) $start,
                $option === 'END' => (string) $end,
                $start === $end => (string) $start,
                default => $start.'-'.substr((string) $end, -2),
            };
        }
        if ($local === null) {
            throw new NumberingException('The date was not worked out for this number; this is a framework fault, not something to configure.', 'timezone');
        }

        return match ($key) {
            'YYYY' => $local->format('Y'),
            'YY' => $local->format('y'),
            'MM' => $local->format('m'),
            'DD' => $local->format('d'),
            default => throw new NumberingException("{{$key}} is not a variable the engine can compute.", $key),
        };
    }
}
