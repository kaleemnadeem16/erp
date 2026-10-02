<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** Parses a number template. Never evaluates anything: literal text, {NAME}, {NAME:option} and exactly one {SEQ:n}. */
final class TemplateParser
{
    public const LITERAL_CHARACTERS = 'A-Za-z0-9\-_\/.';

    public const MAX_LITERAL_RUN = 30;

    public const MAX_TOTAL_LENGTH = 40;

    /** @throws NumberingException */
    public function parse(string $template): ParsedTemplate
    {
        if ($template === '') {
            throw new NumberingException('The format is empty. Add at least the counter, for example {SEQ:5}.', 'template');
        }

        $parts = [];
        $literal = '';
        $length = strlen($template);
        $seqCount = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $template[$i];
            if ($char === '{') {
                $this->flushLiteral($literal, $parts);
                $end = strpos($template, '}', $i);
                if ($end === false) {
                    throw new NumberingException("A '{' is never closed. Variables are written like {YYYY}.", 'template');
                }
                $token = substr($template, $i + 1, $end - $i - 1);
                [$key, $option] = array_pad(explode(':', $token, 2), 2, null);
                if (! preg_match('/^[A-Z][A-Z0-9_]{0,29}$/', (string) $key)) {
                    throw new NumberingException("'{{$token}}' is not a valid variable name. Names are upper-case letters, digits and underscores, like {BRANCH}.", 'template');
                }
                if ($option !== null && ! preg_match('/^[A-Za-z0-9_]{1,10}$/', $option)) {
                    throw new NumberingException("The option in '{{$token}}' is not valid.", 'template');
                }
                if ($key === 'SEQ') {
                    if ($option === null || ! preg_match('/^[0-9]{1,2}$/', $option) || (int) $option < 1 || (int) $option > 12) {
                        throw new NumberingException('The counter is written {SEQ:n} with n from 1 to 12 — the number of digits, e.g. {SEQ:5}.', 'template');
                    }
                    $seqCount++;
                    $parts[] = ['seq', (int) $option];
                } else {
                    $parts[] = ['variable', $key, $option];
                }
                $i = $end;

                continue;
            }
            if ($char === '}') {
                throw new NumberingException("A '}' has no matching '{'.", 'template');
            }
            if (! preg_match('/^['.self::LITERAL_CHARACTERS.']$/', $char)) {
                throw new NumberingException("The character '{$char}' is not allowed in the fixed text. Use letters, digits and - _ / . only (no spaces).", 'template');
            }
            $literal .= $char;
        }
        $this->flushLiteral($literal, $parts);

        if ($seqCount !== 1) {
            throw new NumberingException($seqCount === 0 ? 'The format needs the counter {SEQ:n}.' : 'The format may contain the counter {SEQ:n} only once.', 'template');
        }

        return new ParsedTemplate($parts);
    }

    /** @param  list<array<int, mixed>>  $parts */
    private function flushLiteral(string &$literal, array &$parts): void
    {
        if ($literal === '') {
            return;
        }
        if (strlen($literal) > self::MAX_LITERAL_RUN) {
            throw new NumberingException('A run of fixed text may be at most '.self::MAX_LITERAL_RUN.' characters.', 'template');
        }
        $parts[] = ['literal', $literal];
        $literal = '';
    }
}
