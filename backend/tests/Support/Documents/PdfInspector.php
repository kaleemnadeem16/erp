<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

/** A tiny structural checker for the renderer's own output: cross-reference offsets, page tree, media boxes and the text drawn on each page. Not a general PDF parser. */
final class PdfInspector
{
    /** @var array<int, string> */
    public array $objects = [];

    /** @var list<string> page content streams */
    public array $contents = [];

    /** @var list<array{0: float, 1: float}> */
    public array $mediaBoxes = [];

    public function __construct(public readonly string $bytes)
    {
        $this->parse();
    }

    private function parse(): void
    {
        $b = $this->bytes;
        if (! str_starts_with($b, "%PDF-1.4\n") || ! str_ends_with($b, "%%EOF\n")) {
            throw new \RuntimeException('Not a PDF 1.4 file with an EOF marker.');
        }
        if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $b, $m) !== 1) {
            throw new \RuntimeException('No startxref.');
        }
        $xref = (int) $m[1];
        if (substr($b, $xref, 5) !== "xref\n") {
            throw new \RuntimeException('startxref does not point at the cross-reference table.');
        }
        preg_match('/^xref\n0 (\d+)\n/', substr($b, $xref, 40), $head);
        $count = (int) $head[1];
        $entries = substr($b, $xref + strlen($head[0]), $count * 20);
        for ($i = 1; $i < $count; $i++) {
            $entry = substr($entries, $i * 20, 20);
            if (preg_match('/^(\d{10}) 00000 n \n$/', $entry, $e) !== 1) {
                throw new \RuntimeException("Bad cross-reference entry {$i}.");
            }
            $offset = (int) $e[1];
            if (preg_match('/^'.$i.' 0 obj\n/', substr($b, $offset, 20)) !== 1) {
                throw new \RuntimeException("Object {$i} is not at its recorded offset.");
            }
            $end = strpos($b, "\nendobj\n", $offset);
            $this->objects[$i] = substr($b, $offset, $end - $offset);
        }
        if (! str_contains(substr($b, $xref), '/Size '.$count) || ! str_contains($this->objects[1], '/Type /Catalog')) {
            throw new \RuntimeException('Trailer or catalog is wrong.');
        }
        foreach ($this->objects as $object) {
            if (str_contains($object, '/Type /Page ')) {
                preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $object, $box);
                $this->mediaBoxes[] = [(float) $box[1], (float) $box[2]];
                preg_match('#/Contents (\d+) 0 R#', $object, $c);
                $stream = $this->objects[(int) $c[1]];
                preg_match('/^\d+ 0 obj\n<< \/Length (\d+) >>\nstream\n(.*)\nendstream$/s', $stream, $s);
                if (strlen($s[2]) !== (int) $s[1]) {
                    throw new \RuntimeException('A content stream length is wrong.');
                }
                $this->contents[] = $s[2];
            }
        }
    }

    public function pageCount(): int
    {
        return count($this->contents);
    }

    /** @return list<string> the strings drawn on page $n (1-based), in order */
    public function texts(int $n): array
    {
        preg_match_all('/\(((?:[^\\\\()]|\\\\.)*)\) Tj/s', $this->contents[$n - 1], $m);

        return array_map(fn (string $s) => str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s), $m[1]);
    }

    public function allText(): string
    {
        $out = [];
        foreach (array_keys($this->contents) as $i) {
            $out[] = implode("\n", $this->texts($i + 1));
        }

        return implode("\n", $out);
    }
}
