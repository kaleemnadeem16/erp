<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** Joins fixed text, resolved variable text and the zero-padded counter into the final number. */
final class NumberRenderer
{
    /** @param  array<string, string>  $resolved variable token (KEY or KEY:option) => text */
    public function render(ParsedTemplate $template, array $resolved, int $counterValue): string
    {
        $out = '';
        foreach ($template->parts as $part) {
            $out .= match ($part[0]) {
                'literal' => $part[1],
                'variable' => $resolved[$part[1].(($part[2] ?? null) !== null ? ':'.$part[2] : '')] ?? '',
                'seq' => str_pad((string) $counterValue, (int) $part[1], '0', STR_PAD_LEFT),
                default => throw new \LogicException('Unknown template part '.$part[0]),
            };
        }

        return $out;
    }
}
