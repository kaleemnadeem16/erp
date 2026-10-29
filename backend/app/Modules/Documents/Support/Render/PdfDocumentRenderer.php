<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\FieldType;

/**
 * The first renderer: schema-driven A4/Letter PDF from the curated layouts (`standard-table`, `compact-table`). It draws the owner's header fields, then the permitted
 * sections in the template's order — a repeating section as a table whose header row repeats on every page and whose rows are kept whole, anything else as labelled
 * values — with the page policy's margins and header and footer bands, and "page n of N". A DRAFT_PREVIEW is conspicuously marked on every page and can be mistaken for
 * nothing official. Money and quantities are printed exactly as the frozen strings; nothing is computed. Standard fonts only (Helvetica, not embedded), English, left to right.
 * Deterministic: no clock, no randomness, no compression.
 */
final class PdfDocumentRenderer implements DocumentRenderer
{
    public const CONTRACT = 'document-pdf/1';

    public const VERSION = '1.0.0';

    private const PT_PER_MM = 72 / 25.4;

    public function contract(): string
    {
        return self::CONTRACT;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function fonts(): array
    {
        return [
            ['family' => 'Helvetica', 'style' => 'regular', 'embedded' => false, 'encoding' => 'WinAnsi'],
            ['family' => 'Helvetica', 'style' => 'bold', 'embedded' => false, 'encoding' => 'WinAnsi'],
        ];
    }

    public function render(RenderInput $input): RenderedDocument
    {
        $page = $input->pagePolicy['configuration'] ?? null;
        $template = $input->template['configuration'] ?? null;
        if (! is_array($page) || ! is_array($template) || ! isset($page['width_mm'], $template['layout_id'])) {
            throw new DocumentException('The manifest does not carry a resolved template and page policy.', null, 'manifest_unrenderable');
        }
        $layout = new PdfLayout($input, $page, $template, $template['layout_id'] === DocumentLayouts::COMPACT);
        $pages = $layout->pages();

        $writer = new PdfWriter($input->title, 'ERP document renderer '.self::CONTRACT.' '.self::VERSION, \DateTimeImmutable::createFromInterface($input->created)->setTimezone(new \DateTimeZone('UTC')), $input->manifestDigest);
        foreach ($pages as $content) {
            $writer->addPage($layout->width, $layout->height, $content);
        }
        $bytes = $writer->output();

        return new RenderedDocument($bytes, $writer->pageCount(), hash('sha256', $bytes));
    }

    /** The purpose-independent marker of an official-looking original: a DRAFT_PREVIEW never gets it. */
    public static function isDraft(string $purpose): bool
    {
        return $purpose === DocumentManifest::DRAFT_PREVIEW;
    }

    public static function formatValue(mixed $value, FieldType $type): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }
}
