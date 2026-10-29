<?php

declare(strict_types=1);

namespace Tests\Support\Files;

/** Tiny, real file bodies for the Files tests: valid PNG/JPEG/PDF, and the kinds of content the kernel must refuse. */
final class TestFiles
{
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public static function jpeg(): string
    {
        return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
    }

    public static function html(): string
    {
        return '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
    }

    public static function php(): string
    {
        return "<?php system(\$_GET['c']); ?>\n";
    }

    public static function zip(): string
    {
        return "PK\x03\x04\x14\x00\x00\x00\x00\x00".str_repeat("\x00", 40);
    }

    public static function elf(): string
    {
        return "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 120);
    }

    /** A PNG signature and header with a decodable-looking IHDR of the given size but no image data: sniffs as PNG, and getimagesize reads the dimensions. */
    public static function pngWithDimensions(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    /** @return resource */
    public static function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
