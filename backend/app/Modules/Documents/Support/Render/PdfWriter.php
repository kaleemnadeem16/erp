<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

/**
 * A minimal, deterministic PDF 1.4 writer: pages of drawing operators over two standard fonts, nothing else. No compression, no JavaScript, no actions, no
 * attachments, no external references and no random or wall-clock input — the same pages, title and creation time always give the same bytes. The renderer owns
 * layout; this class only serialises it.
 */
final class PdfWriter
{
    /** @var list<array{width: float, height: float, content: string}> */
    private array $pages = [];

    public function __construct(private readonly string $title, private readonly string $producer, private readonly \DateTimeInterface $created, private readonly string $fileId) {}

    public function addPage(float $width, float $height, string $content): void
    {
        $this->pages[] = ['width' => $width, 'height' => $height, 'content' => $content];
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public static function escape(string $encoded): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', '\\r'], $encoded);
    }

    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    public function output(): string
    {
        // Objects: 1 catalog, 2 pages, 3 info, 4 regular font, 5 bold font, then a page and its content stream per page.
        $objects = [];
        $kids = [];
        $number = 6;
        foreach ($this->pages as $page) {
            $kids[] = $number.' 0 R';
            $objects[$number] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents %d 0 R >>',
                self::number($page['width']), self::number($page['height']), $number + 1);
            $objects[$number + 1] = '<< /Length '.strlen($page['content'])." >>\nstream\n".$page['content']."\nendstream";
            $number += 2;
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[3] = '<< /Title ('.self::escape(StandardFontMetrics::encode($this->title)).') /Producer ('.self::escape($this->producer).') /CreationDate (D:'.$this->created->format('YmdHis').'Z) >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n";
        $out .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        $id = strtoupper(substr($this->fileId, 0, 32));
        $out .= "trailer\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R /Info 3 0 R /ID [<'.$id.'> <'.$id.'>] >>'."\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }
}
