<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/**
 * A template broken into parts: ['literal', text] | ['variable', key, option|null] | ['seq', width].
 * Produced by TemplateParser; never evaluated, only rendered from resolved values.
 */
final class ParsedTemplate
{
    /** @param  list<array{0: string, 1: string|int, 2?: string|null}>  $parts */
    public function __construct(public readonly array $parts) {}

    public function seqWidth(): int
    {
        foreach ($this->parts as $part) {
            if ($part[0] === 'seq') {
                return (int) $part[1];
            }
        }

        return 1;
    }

    /** @return list<array{key: string, option: string|null}> every non-counter variable, in order */
    public function variables(): array
    {
        $out = [];
        foreach ($this->parts as $part) {
            if ($part[0] === 'variable') {
                $out[] = ['key' => (string) $part[1], 'option' => $part[2] ?? null];
            }
        }

        return $out;
    }

    /** The text before the first variable or counter — used to spot two series that could overlap. */
    public function literalPrefix(): string
    {
        $prefix = '';
        foreach ($this->parts as $part) {
            if ($part[0] !== 'literal') {
                break;
            }
            $prefix .= $part[1];
        }

        return $prefix;
    }
}
