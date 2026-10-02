<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\ParsedTemplate;
use App\Modules\Numbering\Support\TemplateParser;
use App\Modules\Numbering\Support\VariableCatalog;
use Carbon\CarbonImmutable;

/** Checks a template and scope against a document type and the company's state (ADR 0079 §5, §6, §8). Reports everything wrong at once. */
final class TemplateValidator
{
    public const MAX_VALUE_LENGTH = 20;

    public function __construct(private readonly TemplateParser $parser, private readonly ConstantsResolver $constants) {}

    /** @return array{template: ParsedTemplate|null, errors: list<string>, maxLength: int} */
    public function validate(NumberableDocumentType $type, string $template, CounterScope $scope): array
    {
        try {
            $parsed = $this->parser->parse($template);
        } catch (NumberingException $e) {
            return ['template' => null, 'errors' => [$e->getMessage()], 'maxLength' => 0];
        }

        $errors = [];
        $supplied = array_map(fn ($v) => $v->key, $type->variables());
        $constants = $this->constants->active();
        $length = $parsed->seqWidth();
        foreach ($parsed->parts as $part) {
            if ($part[0] === 'literal') {
                $length += strlen((string) $part[1]);
            }
        }

        foreach ($parsed->variables() as ['key' => $key, 'option' => $option]) {
            if (VariableCatalog::isEngine($key)) {
                $length += match ($key) {
                    'YYYY' => 4, 'FY' => 9, 'COMPANY' => 10, default => 2,
                };
                if ($key === 'FY' && $option !== null && ! in_array($option, ['START', 'END'], true)) {
                    $errors[] = "{FY:{$option}} is not valid; use {FY}, {FY:START} or {FY:END}.";
                }
                if ($key !== 'FY' && $option !== null) {
                    $errors[] = "{{$key}} takes no option.";
                }
            } elseif (isset($constants[$key])) {
                $length += strlen($constants[$key]);
            } elseif (in_array($key, $supplied, true)) {
                // Estimated from the document type's sample value; the hard limit is enforced when a number is actually issued.
                $length += strlen($type->sampleContext(CarbonImmutable::now())->values[$key]->value ?? str_repeat('X', 8));
            } else {
                $errors[] = "{{$key}} is not available for {$type->label()} numbers. Available: ".$this->available($type, $constants).'.';
            }
        }

        foreach ($scope->dimensions as $dimension) {
            if (! in_array($dimension, $type->dimensions(), true)) {
                $errors[] = "{$dimension} cannot be part of the counter scope for {$type->label()} numbers.";
            }
        }
        if ($length > TemplateParser::MAX_TOTAL_LENGTH) {
            $errors[] = "A number like the sample would be {$length} characters long; the limit is ".TemplateParser::MAX_TOTAL_LENGTH.'.';
        }

        return ['template' => $parsed, 'errors' => $errors, 'maxLength' => $length];
    }

    /** @param  array<string, string>  $constants */
    private function available(NumberableDocumentType $type, array $constants): string
    {
        $keys = [...array_keys(VariableCatalog::ENGINE), ...array_map(fn ($v) => $v->key, $type->variables()), ...array_keys($constants)];

        return implode(', ', array_map(fn (string $k): string => '{'.$k.'}', $keys));
    }
}
