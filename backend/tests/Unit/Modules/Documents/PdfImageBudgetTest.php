<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Documents;

use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\Render\PdfImage;
use PHPUnit\Framework\TestCase;

/** The drawing code re-checks the size budget from the header, so a pinned image that somehow exceeds it is refused before anything is inflated (issue #95). */
final class PdfImageBudgetTest extends TestCase
{
    private function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
    }

    public function test_an_oversized_declared_png_is_refused_before_inflation(): void
    {
        $png = "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', pack('NNCCCCC', 60_000, 60_000, 8, 6, 0, 0, 0)).$this->chunk('IDAT', (string) gzcompress(str_repeat("\0", 1_000_000), 9)).$this->chunk('IEND', '');
        try {
            PdfImage::fromBytes('image/png', $png);
            $this->fail('Expected a refusal.');
        } catch (DocumentException $e) {
            $this->assertSame('brand_asset_unsupported', $e->reasonCode);
        }
    }

    public function test_an_oversized_declared_jpeg_is_refused(): void
    {
        $jpeg = "\xFF\xD8\xFF\xC0\x00\x0B\x08\xFF\xFF\xFF\xFF\x01\x01\x11\x00\xFF\xD9";
        $this->expectException(DocumentException::class);
        PdfImage::fromBytes('image/jpeg', $jpeg);
    }
}
