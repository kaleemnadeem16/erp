<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

/**
 * Text measurement and encoding for the two PDF standard fonts the renderer draws with (Helvetica and Helvetica-Bold, WinAnsi). The widths are the published Adobe
 * core-font AFM advance widths in 1/1000 em for printable ASCII; any other WinAnsi character is measured at the font's average digit width (an approximation, used only
 * for wrapping and alignment — a viewer draws the glyph with its own metrics-compatible font). English / left-to-right only (documents.md): a character outside
 * WinAnsi is drawn as `?`.
 */
final class StandardFontMetrics
{
    /** Helvetica advance widths for 0x20..0x7E. */
    private const REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Helvetica-Bold advance widths for 0x20..0x7E. */
    private const BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** UTF-8 to WinAnsi (CP1252); characters it cannot represent become `?`. */
    public static function encode(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text);

        // mbstring substitutes `?` for anything WinAnsi cannot hold (the same on every host); nothing is dropped silently.
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /** Width of already-encoded text in points. */
    public static function width(string $encoded, float $size, bool $bold = false): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $units = 0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($encoded[$i]);
            $units += $code >= 0x20 && $code <= 0x7E ? $table[$code - 0x20] : ($bold ? 611 : 556);
        }

        return $units * $size / 1000;
    }

    /**
     * Greedy word wrap into lines no wider than $maxWidth; a word longer than a line is broken by character. Always returns at least one line.
     *
     * @return list<string> encoded lines
     */
    public static function wrap(string $encoded, float $maxWidth, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $encoded) ?: [''] as $paragraph) {
            $current = '';
            foreach (preg_split('/ +/', trim($paragraph)) ?: [''] as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                if (self::width($candidate, $size, $bold) <= $maxWidth) {
                    $current = $candidate;

                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                while (self::width($word, $size, $bold) > $maxWidth && strlen($word) > 1) {
                    $cut = strlen($word) - 1;
                    while ($cut > 1 && self::width(substr($word, 0, $cut), $size, $bold) > $maxWidth) {
                        $cut--;
                    }
                    $lines[] = substr($word, 0, $cut);
                    $word = substr($word, $cut);
                }
                $current = $word;
            }
            $lines[] = $current;
        }

        return $lines;
    }
}
