<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Files;

use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\Images\ImageSanitiser;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial inputs for the logo sanitiser (issue #95): size budgets are judged from the declared header BEFORE anything is inflated, truncated and mislabelled files are
 * refused, trailing and embedded foreign data never survives, and the one colour-transform marker a JPEG may keep is rebuilt, not copied.
 */
final class ImageSanitiserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Branding/'.$name);
    }

    private function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
    }

    /** A grey 8-bit PNG whose header declares $w x $h and whose data is $raw (zlib-compressed here). */
    private function png(int $w, int $h, string $raw, ?string $compressed = null): string
    {
        return "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)).$this->chunk('IDAT', $compressed ?? gzcompress($raw, 9)).$this->chunk('IEND', '');
    }

    private function refusal(string $bytes): string
    {
        try {
            ImageSanitiser::sanitise($bytes);
        } catch (FileException $e) {
            return $e->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_png_header_declaring_far_too_many_pixels_is_refused_before_any_inflation(): void
    {
        $before = memory_get_peak_usage(true);
        $this->assertSame('image_too_large', $this->refusal($this->png(100_000, 100_000, "\0")));
        $this->assertSame('image_too_large', $this->refusal($this->png(4_294_967_295, 4_294_967_295, "\0")));
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_the_pixel_count_and_the_longest_side_are_both_bounded(): void
    {
        $this->assertSame('image_too_large', $this->refusal($this->png(1001, 1000, "\0")));
        $this->assertSame('image_too_large', $this->refusal($this->png(4097, 1, "\0")));
        $this->assertSame('image_too_large', $this->refusal($this->png(1, 4097, "\0")));
        $ok = ImageSanitiser::sanitise($this->png(4, 2, str_repeat("\0", 2 * 5)));
        $this->assertSame([4, 2], [$ok['width'], $ok['height']]);
    }

    public function test_a_small_header_with_a_compression_bomb_in_the_data_is_refused_without_inflating_it(): void
    {
        $bomb = gzcompress(str_repeat("\0", 64 * 1024 * 1024), 9);
        $this->assertLessThan(200_000, strlen($bomb));
        $before = memory_get_peak_usage(true);
        $this->assertSame('logo_damaged', $this->refusal($this->png(10, 10, '', $bomb)));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_input_over_the_byte_ceiling_is_refused_before_parsing(): void
    {
        $this->assertSame('logo_too_large', $this->refusal($this->png(1, 1, "\0\0").str_repeat('x', ImageSanitiser::MAX_INPUT_BYTES)));
    }

    public function test_truncated_files_are_refused(): void
    {
        $png = $this->fixture('gray.png');
        $jpeg = $this->fixture('gray.jpg');
        foreach ([8, 20, 33, intdiv(strlen($png), 2), strlen($png) - 5] as $cut) {
            $this->assertContains($this->refusal(substr($png, 0, $cut)), ['logo_damaged', 'logo_type_unsupported']);
        }
        foreach ([2, 10, intdiv(strlen($jpeg), 2), strlen($jpeg) - 2] as $cut) {
            $this->assertContains($this->refusal(substr($jpeg, 0, $cut)), ['logo_damaged', 'logo_type_unsupported']);
        }
    }

    public function test_a_png_with_an_invalid_scanline_filter_is_refused(): void
    {
        $this->assertSame('logo_damaged', $this->refusal($this->png(2, 2, "\x09\0\0\x00\0\0")));
    }

    public function test_wrong_or_mixed_content_is_refused_by_what_it_is_not_by_its_name(): void
    {
        $this->assertSame('logo_type_unsupported', $this->refusal('<?php echo 1; ?>'));
        $this->assertSame('logo_type_unsupported', $this->refusal($this->fixture('logo.gif')));
        $this->assertSame('logo_type_unsupported', $this->refusal($this->fixture('logo.svg')));
        $this->assertSame('logo_type_unsupported', $this->refusal('GIF89a'.$this->fixture('gray.png')));
    }

    public function test_trailing_and_embedded_foreign_data_does_not_survive_in_a_png(): void
    {
        $png = $this->png(2, 1, "\0\0\0");
        $hostile = substr($png, 0, -12).$this->chunk('tEXt', "Comment\0<script>alert(1)</script>PK\x03\x04").$this->chunk('IEND', '').'PK'."\x03\x04<?php evil(); ?>";
        $out = ImageSanitiser::sanitise($hostile)['bytes'];
        $this->assertStringEndsWith($this->chunk('IEND', ''), $out);
        foreach (['script', 'PK', '<?php', 'evil'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
    }

    public function test_trailing_and_embedded_foreign_data_does_not_survive_in_a_jpeg(): void
    {
        $jpeg = $this->fixture('gray.jpg');
        $hostile = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', 2 + 18).'<script>x</script>'.substr($jpeg, 2).'PK'."\x03\x04<?php evil(); ?>";
        $out = ImageSanitiser::sanitise($hostile)['bytes'];
        $this->assertStringEndsWith("\xFF\xD9", $out);
        foreach (['script', 'PK', '<?php', 'evil'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
    }

    public function test_a_jpeg_declaring_huge_dimensions_is_refused_from_the_frame_header(): void
    {
        $jpeg = $this->fixture('gray.jpg');
        $at = (int) strpos($jpeg, "\xFF\xC0");
        $this->assertGreaterThan(0, $at);
        $huge = substr_replace($jpeg, "\xFF\xFF\xFF\xFF", $at + 5, 4);
        $this->assertSame('image_too_large', $this->refusal($huge));
    }

    public function test_the_adobe_marker_is_rebuilt_from_one_validated_value_never_copied(): void
    {
        $jpeg = $this->fixture('gray.jpg');
        $adobe = static fn (string $payload): string => substr($jpeg, 0, 2)."\xFF\xEE".pack('n', 2 + strlen($payload)).$payload.substr($jpeg, 2);

        $out = ImageSanitiser::sanitise($adobe("Adobe\x00\x64\x40\x00\x80\x00\x01"))['bytes'];
        $this->assertStringContainsString("\xFF\xEE\x00\x0EAdobe\x00\x64\x00\x00\x00\x00\x01", $out);
        $this->assertStringNotContainsString("\x40\x00\x80", $out);
        $this->assertSame('logo_damaged', $this->refusal($adobe("Adobe\x00\x64\x00\x00\x00\x00\x09")));
        $this->assertSame('logo_damaged', $this->refusal($adobe("Adobe\x00\x64\x00\x00\x00\x00\x01<script>payload</script>")));
    }

    public function test_a_jpeg_scan_without_its_tables_or_frame_is_refused(): void
    {
        $jpeg = $this->fixture('gray.jpg');
        $noHuffman = preg_replace('/\xFF\xC4..(?:.*?)(?=\xFF[\xC0-\xDB])/s', '', $jpeg);
        $this->assertNotSame($jpeg, $noHuffman);
        $this->assertContains($this->refusal((string) $noHuffman), ['logo_damaged']);
    }
}
