<?php

namespace Tests\Unit\Modules\Documents;

use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\Render\PdfDocumentRenderer;
use App\Modules\Documents\Support\Render\RenderInput;
use App\Modules\Documents\Support\Render\StandardFontMetrics;
use PHPUnit\Framework\TestCase;
use Tests\Support\Documents\PdfInspector;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;

/**
 * DF3 — the PDF renderer as a pure function of its input: valid structure, the page policy's size and margins, every permitted section, repeated table headers and whole rows
 * across pages, "page n of N", a conspicuous DRAFT, byte-for-byte determinism, and nothing active in the file. No database, no clock, no network.
 */
class PdfDocumentRendererTest extends TestCase
{
    private const CREATED = '2026-10-29 09:30:00';

    private function page(array $over = []): array
    {
        return ['source' => 'SHIPPED', 'version_id' => null, 'key' => 'default', 'version' => 1, 'configuration' => [...[
            'format' => 'A4', 'orientation' => 'PORTRAIT', 'width_mm' => '210.0', 'height_mm' => '297.0', 'margin_top_mm' => '20', 'margin_right_mm' => '20', 'margin_bottom_mm' => '20', 'margin_left_mm' => '20',
            'first_header' => 'FULL', 'continuation_header' => 'COMPACT', 'first_footer' => 'PAGE_NUMBER_OF_TOTAL', 'continuation_footer' => 'PAGE_NUMBER_OF_TOTAL', 'compatible_layouts' => ['standard-table', 'compact-table'],
        ], ...$over]];
    }

    private function template(array $over = []): array
    {
        return ['source' => 'SHIPPED', 'version_id' => null, 'key' => 'standard', 'version' => 1, 'configuration' => [...[
            'layout_id' => 'standard-table', 'permitted_sections' => ['lines', 'terms'], 'mandatory_sections' => ['lines'], 'repeat_table_header' => true, 'keep_rows_together' => true,
        ], ...$over]];
    }

    private function input(?array $payload = null, string $purpose = DocumentManifest::ORIGINAL, ?array $page = null, ?array $template = null): RenderInput
    {
        return new RenderInput('Purchase order', $purpose, $payload ?? PurchaseOrderShapedAdapter::projection()->payload, (new PurchaseOrderShapedAdapter)->projectionSchema(),
            $template ?? $this->template(), $page ?? $this->page(), new \DateTimeImmutable(self::CREATED, new \DateTimeZone('UTC')), str_repeat('ab', 32));
    }

    private function manyLines(int $count): array
    {
        $payload = PurchaseOrderShapedAdapter::projection()->payload;
        $payload['sections']['lines'] = [];
        for ($i = 1; $i <= $count; $i++) {
            $payload['sections']['lines'][] = ['description' => "Line item number {$i} with a reasonably descriptive text", 'quantity' => "{$i}.0000", 'uom' => 'kg', 'unit_price' => '10.0000', 'amount' => ($i * 10).'.0000'];
        }

        return $payload;
    }

    public function test_a_single_page_document_is_structurally_valid_and_carries_every_fact_exactly(): void
    {
        $rendered = (new PdfDocumentRenderer)->render($this->input());
        $pdf = new PdfInspector($rendered->bytes);

        $this->assertSame(1, $pdf->pageCount());
        $this->assertSame(1, $rendered->pages);
        $this->assertSame(hash('sha256', $rendered->bytes), $rendered->sha256);
        $this->assertEqualsWithDelta([595.28, 841.89], $pdf->mediaBoxes[0], 0.01, 'A4 portrait');
        $text = $pdf->allText();
        foreach (['Purchase order', 'Po number', 'PO-0001', 'Supplier name', 'Acme Supplies', 'PKR', '2026-10-21', '1250.5000', 'Lines', 'Description', 'Pigment', 'Solvent', '1000.0000', '250.5000', '50.1000', 'Terms', 'Payment terms', 'Net 30', 'Page 1 of 1'] as $expected) {
            $this->assertStringContainsString($expected, $text, $expected);
        }
        $this->assertStringNotContainsString('DRAFT', $text, 'an ORIGINAL carries no draft marking');
        $this->assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->buffer($rendered->bytes));
        $this->assertSame(1, preg_match('/^%PDF-1\.4/', $rendered->bytes));
    }

    public function test_the_output_is_deterministic_and_changes_only_with_its_inputs(): void
    {
        $a = (new PdfDocumentRenderer)->render($this->input());
        $b = (new PdfDocumentRenderer)->render($this->input());
        $this->assertSame($a->bytes, $b->bytes, 'byte-for-byte reproducible for the same input and renderer version');

        $later = new RenderInput('Purchase order', DocumentManifest::ORIGINAL, PurchaseOrderShapedAdapter::projection()->payload, (new PurchaseOrderShapedAdapter)->projectionSchema(), $this->template(), $this->page(),
            new \DateTimeImmutable('2026-10-30 09:30:00', new \DateTimeZone('UTC')), str_repeat('ab', 32));
        $this->assertNotSame($a->bytes, (new PdfDocumentRenderer)->render($later)->bytes);
        $payload = PurchaseOrderShapedAdapter::projection()->payload;
        $payload['header']['total'] = '9999.0000';
        $this->assertNotSame($a->bytes, (new PdfDocumentRenderer)->render($this->input($payload))->bytes);
        $this->assertStringContainsString('(ERP document renderer document-pdf/1 1.0.0)', $a->bytes);
        $this->assertSame('document-pdf/1', (new PdfDocumentRenderer)->contract());
        $this->assertSame('1.0.0', (new PdfDocumentRenderer)->version());
        $this->assertSame([['Helvetica', 'regular', false], ['Helvetica', 'bold', false]], array_map(fn ($f) => [$f['family'], $f['style'], $f['embedded']], (new PdfDocumentRenderer)->fonts()));
    }

    public function test_a_long_table_flows_onto_further_pages_with_a_repeated_header_whole_rows_and_exact_page_numbers(): void
    {
        $rendered = (new PdfDocumentRenderer)->render($this->input($this->manyLines(120)));
        $pdf = new PdfInspector($rendered->bytes);
        $pages = $pdf->pageCount();

        $this->assertGreaterThan(2, $pages);
        $seen = [];
        for ($n = 1; $n <= $pages; $n++) {
            $texts = $pdf->texts($n);
            $this->assertContains('Page '.$n.' of '.$pages, $texts, "footer of page {$n}");
            if ($n > 1) {
                $this->assertContains('Description', $texts, "the table header repeats on page {$n}");
                $this->assertContains('Unit price', $texts);
            }
            foreach ($texts as $text) {
                if (preg_match('/^Line item number (\d+) /', $text, $m) === 1) {
                    $seen[] = (int) $m[1];
                }
            }
        }
        $this->assertSame(range(1, 120), $seen, 'every row exactly once, in order, none split');
        // Continuation pages use the compact header; the first uses the full one (larger title).
        $this->assertStringContainsString('14 Tf', $pdf->contents[0]);
        $this->assertStringNotContainsString(' 14 Tf', $pdf->contents[1]);
    }

    public function test_long_cell_text_wraps_inside_its_column_and_never_overflows_the_page(): void
    {
        $payload = $this->manyLines(2);
        $payload['sections']['lines'][0]['description'] = str_repeat('Extremely long unbroken description text that must wrap. ', 12).'Averyveryveryveryveryveryveryveryveryveryveryveryveryverylongsingleword';
        $rendered = (new PdfDocumentRenderer)->render($this->input($payload));
        $pdf = new PdfInspector($rendered->bytes);
        $width = $pdf->mediaBoxes[0][0];
        foreach ($pdf->texts(1) as $text) {
            $this->assertLessThanOrEqual($width - 40 - 40, StandardFontMetrics::width(StandardFontMetrics::encode($text), 10, true) + 0.0, 'no drawn string is wider than the content area');
        }
        $this->assertStringContainsString('Extremely long', $pdf->allText());
    }

    public function test_the_page_policy_sets_size_orientation_and_bands(): void
    {
        $letter = $this->page(['format' => 'LETTER', 'width_mm' => '215.9', 'height_mm' => '279.4', 'first_header' => 'NONE', 'first_footer' => 'NONE']);
        $pdf = new PdfInspector((new PdfDocumentRenderer)->render($this->input(page: $letter))->bytes);
        $this->assertEqualsWithDelta([612.00, 792.00], $pdf->mediaBoxes[0], 0.05, 'US Letter');
        $this->assertStringNotContainsString('Page 1', $pdf->allText(), 'no footer band');
        $this->assertNotContains('Purchase order', array_slice($pdf->texts(1), 0, 1), 'no header band: the first string is a field, not the title');

        $landscape = $this->page(['orientation' => 'LANDSCAPE', 'width_mm' => '297.0', 'height_mm' => '210.0', 'first_footer' => 'PAGE_NUMBER']);
        $pdf = new PdfInspector((new PdfDocumentRenderer)->render($this->input(page: $landscape))->bytes);
        $this->assertEqualsWithDelta([841.89, 595.28], $pdf->mediaBoxes[0], 0.01);
        $this->assertContains('Page 1', $pdf->texts(1), 'PAGE_NUMBER without the total');
        $this->assertNotContains('Page 1 of 1', $pdf->texts(1));
    }

    public function test_only_permitted_sections_are_drawn_and_the_compact_layout_is_smaller(): void
    {
        $noTerms = (new PdfInspector((new PdfDocumentRenderer)->render($this->input(template: $this->template(['permitted_sections' => ['lines'], 'mandatory_sections' => ['lines']])))->bytes))->allText();
        $this->assertStringNotContainsString('Net 30', $noTerms);
        $this->assertStringContainsString('Pigment', $noTerms);

        $standard = new PdfInspector((new PdfDocumentRenderer)->render($this->input($this->manyLines(40)))->bytes);
        $compact = new PdfInspector((new PdfDocumentRenderer)->render($this->input($this->manyLines(40), template: $this->template(['layout_id' => 'compact-table'])))->bytes);
        $this->assertLessThanOrEqual($standard->pageCount(), $compact->pageCount(), 'compact fits at least as much per page');
    }

    public function test_a_draft_preview_is_marked_on_every_page_and_an_original_never_is(): void
    {
        $draft = new PdfInspector((new PdfDocumentRenderer)->render($this->input($this->manyLines(80), DocumentManifest::DRAFT_PREVIEW))->bytes);
        for ($n = 1; $n <= $draft->pageCount(); $n++) {
            $this->assertContains('DRAFT', $draft->texts($n), "watermark on page {$n}");
            $this->assertContains('DRAFT - NOT AN OFFICIAL DOCUMENT', $draft->texts($n), "header label on page {$n}");
        }
        $original = new PdfInspector((new PdfDocumentRenderer)->render($this->input($this->manyLines(80)))->bytes);
        $this->assertStringNotContainsString('DRAFT', $original->allText());
    }

    public function test_text_is_escaped_encoded_and_unsupported_characters_are_visible_not_silent(): void
    {
        $payload = PurchaseOrderShapedAdapter::projection()->payload;
        $payload['header']['supplier_name'] = 'Acme (Pvt) Ltd \\ Söhne ‑ 日本';
        $pdf = new PdfInspector((new PdfDocumentRenderer)->render($this->input($payload))->bytes);
        $text = $pdf->allText();
        $this->assertStringContainsString('Acme (Pvt) Ltd \\ S', $text, 'parentheses and backslashes survive escaping');
        $this->assertStringContainsString("S\xF6hne", $text, 'a WinAnsi character is encoded, not mangled');
        $this->assertStringContainsString('?', $text, 'a character outside WinAnsi is shown as ? rather than dropped');
    }

    public function test_the_file_contains_nothing_active_and_no_external_reference(): void
    {
        $bytes = (new PdfDocumentRenderer)->render($this->input($this->manyLines(5), DocumentManifest::DRAFT_PREVIEW))->bytes;
        foreach (['/JS', '/JavaScript', '/Launch', '/URI', '/OpenAction', '/AA', '/EmbeddedFile', '/GoToR', '/SubmitForm', '/XFA', '/AcroForm', '/Encrypt'] as $active) {
            $this->assertStringNotContainsString($active, $bytes, $active);
        }
    }

    public function test_an_input_without_a_resolved_configuration_is_refused(): void
    {
        $this->expectException(DocumentException::class);
        (new PdfDocumentRenderer)->render(new RenderInput('x', DocumentManifest::ORIGINAL, [], (new PurchaseOrderShapedAdapter)->projectionSchema(), [], [], new \DateTimeImmutable, str_repeat('a', 64)));
    }
}
