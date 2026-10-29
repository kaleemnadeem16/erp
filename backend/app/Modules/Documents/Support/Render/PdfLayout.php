<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

use App\Modules\Documents\Support\FieldSpec;
use App\Modules\Documents\Support\FieldType;

/**
 * Lays one document out into page content streams. Top-down flow in points; every page is built in full before any footer is drawn so "page n of N" is exact. Row
 * heights come from wrapped text measured with the standard-font metrics; a row never splits across pages unless it is taller than a page. All input is the frozen
 * manifest — nothing is read from anywhere else.
 */
final class PdfLayout
{
    private const MM = 72 / 25.4;

    public readonly float $width;

    public readonly float $height;

    private float $mLeft;

    private float $mRight;

    private float $mTop;

    private float $mBottom;

    private float $size;

    private float $leading;

    private float $pad;

    private bool $draft;

    /** @var list<array{ops: string, headerMode: string, footerMode: string}> */
    private array $pages = [];

    private float $y = 0.0;

    private float $contentTop = 0.0;

    private float $contentBottom = 0.0;

    /** @param array<string, mixed> $page @param array<string, mixed> $template */
    public function __construct(private readonly RenderInput $input, private readonly array $page, private readonly array $template, bool $compact)
    {
        $this->width = round((float) $page['width_mm'] * self::MM, 2);
        $this->height = round((float) $page['height_mm'] * self::MM, 2);
        $this->mLeft = round((float) $page['margin_left_mm'] * self::MM, 2);
        $this->mRight = round((float) $page['margin_right_mm'] * self::MM, 2);
        $this->mTop = round((float) $page['margin_top_mm'] * self::MM, 2);
        $this->mBottom = round((float) $page['margin_bottom_mm'] * self::MM, 2);
        $this->size = $compact ? 8.5 : 10.0;
        $this->leading = $this->size * 1.25;
        $this->pad = $compact ? 2.0 : 4.0;
        $this->draft = PdfDocumentRenderer::isDraft($input->purpose);
    }

    /** @return list<string> */
    public function pages(): array
    {
        $this->newPage();
        $this->body();
        $total = count($this->pages);
        $out = [];
        foreach ($this->pages as $index => $page) {
            $ops = ($this->draft ? $this->watermark() : '').$page['ops'].$this->footer($page['footerMode'], $index + 1, $total);
            $out[] = $ops;
        }

        return $out;
    }

    // ---- pages and bands -----------------------------------------------------------------------------------------------------------------------------

    private function newPage(): void
    {
        $first = $this->pages === [];
        $headerMode = (string) ($first ? $this->page['first_header'] : $this->page['continuation_header']);
        $footerMode = (string) ($first ? $this->page['first_footer'] : $this->page['continuation_footer']);
        $headerHeight = match ($headerMode) {
            'FULL' => 38.0,
            'COMPACT' => 20.0,
            default => 0.0,
        };
        $footerHeight = $footerMode === 'NONE' ? 0.0 : 22.0;
        $this->contentTop = $this->height - $this->mTop - $headerHeight;
        $this->contentBottom = $this->mBottom + $footerHeight;
        $this->y = $this->contentTop;
        $ops = $this->header($headerMode);
        $this->pages[] = ['ops' => $ops, 'headerMode' => $headerMode, 'footerMode' => $footerMode];
    }

    private function emit(string $ops): void
    {
        $this->pages[count($this->pages) - 1]['ops'] .= $ops;
    }

    private function header(string $mode): string
    {
        if ($mode === 'NONE') {
            return '';
        }
        $full = $mode === 'FULL';
        $size = $full ? 14.0 : 9.0;
        $top = $this->height - $this->mTop;
        $ops = $this->text($this->mLeft, $top - ($full ? 14 : 8), $this->input->title, $size, true);
        if ($this->draft) {
            $label = 'DRAFT - NOT AN OFFICIAL DOCUMENT';
            $encoded = StandardFontMetrics::encode($label);
            $x = $this->width - $this->mRight - StandardFontMetrics::width($encoded, 8, true);
            $ops .= $this->text($x, $top - ($full ? 12 : 7), $label, 8, true, '0.75 0 0');
        }

        return $ops.$this->rule($this->mLeft, $top - ($full ? 24 : 14), $this->width - $this->mRight, 0.5);
    }

    private function footer(string $mode, int $number, int $total): string
    {
        if ($mode === 'NONE') {
            return '';
        }
        $label = $mode === 'PAGE_NUMBER_OF_TOTAL' ? "Page {$number} of {$total}" : "Page {$number}";
        $encoded = StandardFontMetrics::encode($label);
        $x = ($this->width - StandardFontMetrics::width($encoded, 8)) / 2;

        return $this->rule($this->mLeft, $this->mBottom + 14, $this->width - $this->mRight, 0.4).$this->text($x, $this->mBottom + 4, $label, 8, false, '0.35');
    }

    private function watermark(): string
    {
        $size = 96.0;
        $width = StandardFontMetrics::width('DRAFT', $size, true);
        $cx = PdfWriter::number($this->width / 2);
        $cy = PdfWriter::number($this->height / 2);

        return sprintf("q 0.7071 0.7071 -0.7071 0.7071 %s %s cm BT /F2 %s Tf 0.9 g %s %s Td (DRAFT) Tj ET Q\n", $cx, $cy, PdfWriter::number($size), PdfWriter::number(-$width / 2), PdfWriter::number(-$size * 0.35));
    }

    // ---- drawing primitives --------------------------------------------------------------------------------------------------------------------------

    /** @param string $gray a PDF grey level ("0.35") or an RGB triple ("0.75 0 0") */
    private function text(float $x, float $y, string $text, float $size, bool $bold = false, string $gray = '0', bool $encoded = false): string
    {
        $colour = str_contains($gray, ' ') ? "{$gray} rg" : "{$gray} g";

        return sprintf("BT /%s %s Tf %s %s %s Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', PdfWriter::number($size), $colour, PdfWriter::number($x), PdfWriter::number($y), PdfWriter::escape($encoded ? $text : StandardFontMetrics::encode($text)))
            .($gray === '0' ? '' : "0 g\n");
    }

    private function rule(float $x1, float $y, float $x2, float $weight): string
    {
        return sprintf("%s w %s %s m %s %s l S\n", PdfWriter::number($weight), PdfWriter::number($x1), PdfWriter::number($y), PdfWriter::number($x2), PdfWriter::number($y));
    }

    private function fill(float $x, float $y, float $w, float $h, string $gray): string
    {
        return sprintf("%s g %s %s %s %s re f 0 g\n", $gray, PdfWriter::number($x), PdfWriter::number($y), PdfWriter::number($w), PdfWriter::number($h));
    }

    // ---- body ----------------------------------------------------------------------------------------------------------------------------------------

    private function body(): void
    {
        $payload = $this->input->payload;
        $headerFields = [];
        foreach ($this->input->schema->header as $key => $spec) {
            $value = $payload['header'][$key] ?? null;
            if ($value !== null) {
                $headerFields[] = [self::humanise($key), PdfDocumentRenderer::formatValue($value, $spec->type)];
            }
        }
        $this->pairs($headerFields);

        $permitted = is_array($this->template['permitted_sections'] ?? null) ? $this->template['permitted_sections'] : array_keys($this->input->schema->sections);
        foreach ($permitted as $name) {
            $schema = $this->input->schema->sections[$name] ?? null;
            $data = $payload['sections'][$name] ?? null;
            if ($schema === null || $data === null) {
                continue;
            }
            $this->ensure(40.0);
            $this->emit($this->text($this->mLeft, $this->y - 12, self::humanise($name), 11, true));
            $this->y -= 20;
            if ($schema->repeating) {
                $this->table($schema->fields, is_array($data) ? array_values($data) : []);
            } else {
                $fields = [];
                foreach ($schema->fields as $key => $spec) {
                    if (($data[$key] ?? null) !== null) {
                        $fields[] = [self::humanise($key), PdfDocumentRenderer::formatValue($data[$key], $spec->type)];
                    }
                }
                $this->pairs($fields);
            }
            $this->y -= 8;
        }
    }

    private function ensure(float $needed): void
    {
        if ($this->y - $needed < $this->contentBottom) {
            $this->newPage();
        }
    }

    /** @param list<array{0: string, 1: string}> $fields label/value pairs in two columns */
    private function pairs(array $fields): void
    {
        $contentWidth = $this->width - $this->mLeft - $this->mRight;
        $column = ($contentWidth - 12) / 2;
        for ($i = 0; $i < count($fields); $i += 2) {
            $cells = array_filter([$fields[$i], $fields[$i + 1] ?? null]);
            $wrapped = [];
            $rowHeight = 0.0;
            foreach ($cells as $index => [$label, $value]) {
                $lines = StandardFontMetrics::wrap(StandardFontMetrics::encode($value), $column, $this->size);
                $wrapped[$index] = [$label, $lines];
                $rowHeight = max($rowHeight, 11 + count($lines) * $this->leading + 4);
            }
            $this->ensure($rowHeight);
            foreach ($wrapped as $index => [$label, $lines]) {
                $x = $this->mLeft + $index * ($column + 12);
                $this->emit($this->text($x, $this->y - 8, $label, 7.5, true, '0.35'));
                foreach ($lines as $n => $line) {
                    $this->emit($this->text($x, $this->y - 11 - ($n + 1) * $this->leading + ($this->leading - $this->size) / 2 + 1, $line, $this->size, false, '0', true));
                }
            }
            $this->y -= $rowHeight;
        }
        $this->y -= 6;
    }

    /**
     * @param  array<string, FieldSpec>  $fields
     * @param  list<mixed>  $rows
     */
    private function table(array $fields, array $rows): void
    {
        $contentWidth = $this->width - $this->mLeft - $this->mRight;
        $columns = [];
        foreach ($fields as $key => $spec) {
            $columns[] = ['key' => $key, 'label' => self::humanise($key), 'numeric' => in_array($spec->type, [FieldType::Decimal, FieldType::Integer], true), 'type' => $spec->type];
        }
        if ($columns === []) {
            return;
        }
        $cells = [];
        foreach ($rows as $r => $row) {
            foreach ($columns as $c => $column) {
                $cells[$r][$c] = StandardFontMetrics::encode(PdfDocumentRenderer::formatValue(is_array($row) ? ($row[$column['key']] ?? null) : null, $column['type']));
            }
        }
        $widths = $this->columnWidths($columns, $cells, $contentWidth);
        $repeatHeader = (bool) ($this->template['repeat_table_header'] ?? true);
        $keepTogether = (bool) ($this->template['keep_rows_together'] ?? true);

        $this->tableHeader($columns, $widths);
        if ($rows === []) {
            $this->emit($this->text($this->mLeft + $this->pad, $this->y - $this->leading, '(none)', $this->size, false, '0.35'));
            $this->y -= $this->leading + 6;

            return;
        }
        foreach ($cells as $row) {
            $lines = [];
            $count = 1;
            foreach ($columns as $c => $column) {
                $lines[$c] = StandardFontMetrics::wrap($row[$c], $widths[$c] - 2 * $this->pad, $this->size);
                $count = max($count, count($lines[$c]));
            }
            $rowHeight = $count * $this->leading + $this->pad * 1.5;
            $pageHeight = $this->contentTop - $this->contentBottom;
            if ($this->y - $rowHeight < $this->contentBottom && ($keepTogether || $rowHeight <= $pageHeight)) {
                $this->newPage();
                if ($repeatHeader) {
                    $this->tableHeader($columns, $widths);
                }
            }
            $this->tableRow($columns, $widths, $lines, $count, $rowHeight, $repeatHeader);
        }
    }

    /** @param list<array<string, mixed>> $columns @param list<list<string>> $widths */
    private function tableHeader(array $columns, array $widths): void
    {
        $this->ensure($this->leading + $this->pad * 2 + 24);
        $height = $this->leading + $this->pad;
        $this->emit($this->fill($this->mLeft, $this->y - $height, array_sum($widths), $height, '0.92'));
        $x = $this->mLeft;
        foreach ($columns as $c => $column) {
            $encoded = StandardFontMetrics::encode($column['label']);
            $textX = $column['numeric'] ? $x + $widths[$c] - $this->pad - StandardFontMetrics::width($encoded, $this->size, true) : $x + $this->pad;
            $this->emit($this->text($textX, $this->y - $this->leading + ($this->leading - $this->size) / 2 - 1 + $this->pad / 2, $column['label'], $this->size, true));
            $x += $widths[$c];
        }
        $this->emit($this->rule($this->mLeft, $this->y - $height, $this->mLeft + array_sum($widths), 0.6));
        $this->y -= $height;
    }

    /** @param list<array<string, mixed>> $columns @param list<float> $widths @param list<list<string>> $lines */
    private function tableRow(array $columns, array $widths, array $lines, int $count, float $rowHeight, bool $repeatHeader): void
    {
        $perPage = max(1, (int) floor(($this->contentTop - $this->contentBottom - $this->pad * 1.5) / $this->leading));
        $start = 0;
        while ($start < $count) {
            $fit = min($count - $start, max(1, (int) floor(($this->y - $this->contentBottom - $this->pad * 1.5) / $this->leading)));
            if ($fit < 1 || ($fit < $count - $start && $this->y - $this->contentBottom < $this->leading * 2)) {
                $this->newPage();
                if ($repeatHeader) {
                    $this->tableHeader($columns, $widths);
                }
                $fit = min($count - $start, $perPage);
            }
            $x = $this->mLeft;
            foreach ($columns as $c => $column) {
                for ($n = 0; $n < $fit; $n++) {
                    $line = $lines[$c][$start + $n] ?? null;
                    if ($line === null || $line === '') {
                        continue;
                    }
                    $textX = $column['numeric'] ? $x + $widths[$c] - $this->pad - StandardFontMetrics::width($line, $this->size) : $x + $this->pad;
                    $this->emit(sprintf("BT /F1 %s Tf 0 g %s %s Td (%s) Tj ET\n", PdfWriter::number($this->size), PdfWriter::number($textX),
                        PdfWriter::number($this->y - $this->pad * 0.75 - ($n + 1) * $this->leading + ($this->leading - $this->size) / 2 + 1), PdfWriter::escape($line)));
                }
                $x += $widths[$c];
            }
            $used = $fit * $this->leading + $this->pad * 1.5;
            $this->y -= $used;
            $this->emit($this->rule($this->mLeft, $this->y, $this->mLeft + array_sum($widths), 0.25));
            $start += $fit;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<list<string>>  $cells
     * @return list<float>
     */
    private function columnWidths(array $columns, array $cells, float $available): array
    {
        $natural = [];
        $min = [];
        foreach ($columns as $c => $column) {
            $widest = StandardFontMetrics::width(StandardFontMetrics::encode($column['label']), $this->size, true);
            foreach ($cells as $row) {
                $widest = max($widest, StandardFontMetrics::width($row[$c], $this->size));
            }
            $natural[$c] = $widest + 2 * $this->pad + 2;
            $min[$c] = $column['numeric'] ? $natural[$c] : min($natural[$c], 56.0);
        }
        $total = array_sum($natural);
        if ($total <= $available) {
            $flexible = array_keys(array_filter($columns, fn ($column) => ! $column['numeric']));
            $targets = $flexible === [] ? [count($columns) - 1] : $flexible;
            $extra = ($available - $total) / count($targets);
            foreach ($targets as $c) {
                $natural[$c] += $extra;
            }

            return array_map(fn ($w) => round($w, 2), $natural);
        }
        // Too wide: shrink the text columns toward their minimum first, then everything proportionally.
        $over = $total - $available;
        $room = 0.0;
        foreach ($natural as $c => $w) {
            $room += $w - $min[$c];
        }
        if ($room >= $over && $room > 0) {
            foreach ($natural as $c => $w) {
                $natural[$c] = $w - $over * (($w - $min[$c]) / $room);
            }
        } else {
            $scale = $available / $total;
            foreach ($natural as $c => $w) {
                $natural[$c] = $w * $scale;
            }
        }

        return array_map(fn ($w) => round($w, 2), $natural);
    }

    private static function humanise(string $key): string
    {
        $text = str_replace(['_', '-'], ' ', $key);

        return ucfirst($text);
    }
}
