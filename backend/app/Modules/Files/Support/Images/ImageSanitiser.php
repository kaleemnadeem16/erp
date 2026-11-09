<?php

declare(strict_types=1);

namespace App\Modules\Files\Support\Images;

use App\Modules\Files\Support\FileException;

/**
 * Pure-PHP sanitising of the two raster formats a company logo may be (PNG and JPEG), with no imaging extension. It does not trust the file: it parses the container, refuses
 * anything outside a small supported subset and REBUILDS the file from the parts that matter (the pixel data and what is needed to decode it), dropping every other chunk or
 * segment — text, EXIF, XMP, colour profiles, thumbnails, comments, vendor and unknown ancillary data — so no metadata (a camera's location, an author's name) is stored or printed
 * and no unexpected structure survives. The result is what Files stores, shows and prints; the original bytes are never kept.
 *
 * Supported: PNG that is not interlaced (grey, RGB, palette at 1-8 bits, grey/RGB with alpha at 8 bits); baseline or progressive JPEG with 1 or 3 components. Refused with a reason
 * the person can act on: interlaced or 16-bit PNG, animated PNG (extra frames are dropped by the rebuild, the still image is kept), CMYK or lossless JPEG, damaged data.
 */
final class ImageSanitiser
{
    public const PNG = 'image/png';

    public const JPEG = 'image/jpeg';

    /** The most pixels a sanitised image may have, and the longest side. Both are judged from the declared header BEFORE any pixel data is inflated or copied. */
    public const MAX_PIXELS = 1_000_000;

    public const MAX_DIMENSION = 4096;

    /** The most decoded scanline bytes (filter bytes included) a PNG may inflate to: the hard ceiling handed to the inflater. */
    public const MAX_DECODED_BYTES = 8_388_608;

    /** The most input bytes this class will parse at all. */
    public const MAX_INPUT_BYTES = 2_097_152;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** @return array{bytes: string, media_type: string, width: int, height: int} */
    public static function sanitise(string $bytes, int $maxPixels = self::MAX_PIXELS): array
    {
        if (strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw self::refuse('The logo is larger than 2 MB.', 'logo_too_large');
        }
        $maxPixels = min($maxPixels, self::MAX_PIXELS);
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return self::png($bytes, $maxPixels);
        }
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($bytes, $maxPixels);
        }

        throw self::refuse('Only PNG and JPEG logos are accepted.', 'logo_type_unsupported');
    }

    /** Refuses declared dimensions over the budget, from the header alone, before any pixel data is touched. */
    private static function requireWithinBudget(int $width, int $height, int $maxPixels): void
    {
        if ($width < 1 || $height < 1) {
            throw self::refuse('The image is damaged.', 'logo_damaged');
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > $maxPixels) {
            throw self::refuse('The logo has more than 1 megapixel (for example 1000 x 1000) or a side longer than '.self::MAX_DIMENSION.' pixels; use a smaller image.', 'image_too_large');
        }
    }

    private static function refuse(string $message, string $code): FileException
    {
        return new FileException($message, 'logo', $code);
    }

    // ---- PNG -----------------------------------------------------------------------------------------------------------------------------------------

    /** @return array{bytes: string, media_type: string, width: int, height: int} */
    private static function png(string $bytes, int $maxPixels): array
    {
        $length = strlen($bytes);
        $pos = 8;
        $header = null;
        $palette = null;
        $transparency = null;
        $data = '';
        $sawData = false;
        $dataEnded = false;
        $ended = false;
        while ($pos + 12 <= $length) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if ($size > $length - $pos - 12 || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw self::refuse('The PNG is damaged.', 'logo_damaged');
            }
            $body = substr($bytes, $pos + 8, $size);
            if (unpack('N', substr($bytes, $pos + 8 + $size, 4))[1] !== crc32($type.$body)) {
                throw self::refuse('The PNG is damaged.', 'logo_damaged');
            }
            $pos += 12 + $size;
            if ($header === null && $type !== 'IHDR') {
                throw self::refuse('The PNG is damaged.', 'logo_damaged');
            }
            switch ($type) {
                case 'IHDR':
                    if ($header !== null || $size !== 13) {
                        throw self::refuse('The PNG is damaged.', 'logo_damaged');
                    }
                    $header = $body;
                    $declared = unpack('Nw/Nh', $body);
                    self::requireWithinBudget($declared['w'], $declared['h'], $maxPixels);
                    break;
                case 'PLTE':
                    $palette = $body;
                    break;
                case 'tRNS':
                    $transparency = $body;
                    break;
                case 'IDAT':
                    if ($dataEnded) {
                        throw self::refuse('The PNG is damaged.', 'logo_damaged');
                    }
                    $sawData = true;
                    $data .= $body;
                    break;
                case 'IEND':
                    $ended = true;
                    break 2;
                default:
                    if ($sawData) {
                        $dataEnded = true;
                    }
                    // An unknown CRITICAL chunk (first letter upper case) means a format we cannot be sure we read correctly; ancillary ones are simply not kept.
                    if (ctype_upper($type[0]) && ! in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND'], true)) {
                        throw self::refuse('The PNG uses a feature that is not supported.', 'logo_unsupported_png');
                    }
            }
            if ($type !== 'IDAT' && $sawData) {
                $dataEnded = true;
            }
        }
        if ($header === null || ! $sawData || ! $ended) {
            throw self::refuse('The PNG is damaged.', 'logo_damaged');
        }
        [$width, $height, $depth, $colour, $compression, $filter, $interlace] = array_values(unpack('Nw/Nh/Cd/Cc/Cm/Cf/Ci', $header));
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$colour] ?? null;
        $depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]][$colour] ?? [];
        if ($channels === null || ! in_array($depth, $depths, true) || $compression !== 0 || $filter !== 0 || $width < 1 || $height < 1) {
            throw self::refuse('The PNG is damaged.', 'logo_damaged');
        }
        if ($interlace !== 0) {
            throw self::refuse('Interlaced PNG files are not supported; export the logo without interlacing.', 'logo_interlaced');
        }
        if ($depth === 16) {
            throw self::refuse('16-bit PNG files are not supported; export the logo as an 8-bit PNG.', 'logo_16_bit');
        }
        if ($colour === 3 && ($palette === null || strlen($palette) % 3 !== 0 || strlen($palette) < 3 || strlen($palette) > 768)) {
            throw self::refuse('The PNG palette is damaged.', 'logo_damaged');
        }
        $rowBytes = intdiv($width * $channels * $depth + 7, 8);
        $expected = ($rowBytes + 1) * $height;
        if ($expected > self::MAX_DECODED_BYTES) {
            throw self::refuse('The logo is too large to process; use a smaller image.', 'image_too_large');
        }
        $raw = @gzuncompress($data, $expected + 1);
        if ($raw === false || strlen($raw) !== $expected) {
            throw self::refuse('The PNG image data is damaged.', 'logo_damaged');
        }
        for ($y = 0; $y < $height; $y++) {
            if (ord($raw[$y * ($rowBytes + 1)]) > 4) {
                throw self::refuse('The PNG image data is damaged.', 'logo_damaged');
            }
        }
        $out = self::PNG_SIGNATURE.self::chunk('IHDR', $header);
        if ($colour === 3) {
            $out .= self::chunk('PLTE', (string) $palette);
        }
        if ($transparency !== null && in_array($colour, [0, 2, 3], true)) {
            $out .= self::chunk('tRNS', $transparency);
        }

        return ['bytes' => $out.self::chunk('IDAT', $data).self::chunk('IEND', ''), 'media_type' => self::PNG, 'width' => $width, 'height' => $height];
    }

    private static function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
    }

    // ---- JPEG ----------------------------------------------------------------------------------------------------------------------------------------

    /** @return array{bytes: string, media_type: string, width: int, height: int} */
    private static function jpeg(string $bytes, int $maxPixels): array
    {
        $length = strlen($bytes);
        $pos = 2;
        $out = "\xFF\xD8";
        $width = $height = 0;
        $scans = 0;
        $components = 0;
        $tables = ['quant' => false, 'huffman' => false];
        $sawFrame = false;
        $ended = false;
        while ($pos < $length) {
            if ($bytes[$pos] !== "\xFF") {
                throw self::refuse('The JPEG is damaged.', 'logo_damaged');
            }
            while ($pos + 1 < $length && $bytes[$pos + 1] === "\xFF") {
                $pos++;
            }
            if ($pos + 1 >= $length) {
                break;
            }
            $marker = ord($bytes[$pos + 1]);
            $pos += 2;
            if ($marker === 0xD9) {
                $ended = true;
                break;
            }
            if ($marker === 0xD8 || $marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                throw self::refuse('The JPEG is damaged.', 'logo_damaged');
            }
            if ($pos + 2 > $length) {
                throw self::refuse('The JPEG is damaged.', 'logo_damaged');
            }
            $size = unpack('n', substr($bytes, $pos, 2))[1];
            if ($size < 2 || $pos + $size > $length) {
                throw self::refuse('The JPEG is damaged.', 'logo_damaged');
            }
            $body = substr($bytes, $pos + 2, $size - 2);
            $segment = "\xFF".chr($marker).substr($bytes, $pos, $size);
            $pos += $size;
            if (in_array($marker, [0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                throw self::refuse('This kind of JPEG (lossless or arithmetic coding) is not supported.', 'logo_unsupported_jpeg');
            }
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                if ($sawFrame || $size < 8 || ord($body[0]) !== 8) {
                    throw self::refuse('This kind of JPEG is not supported.', 'logo_unsupported_jpeg');
                }
                [$height, $width] = array_values(unpack('n2', substr($body, 1, 4)));
                $components = ord($body[5]);
                if (! in_array($components, [1, 3], true)) {
                    throw self::refuse('CMYK JPEG files are not supported; export the logo as RGB.', 'logo_cmyk');
                }
                if ($size !== 8 + 3 * $components) {
                    throw self::refuse('The JPEG is damaged.', 'logo_damaged');
                }
                self::requireWithinBudget($width, $height, $maxPixels);
                $sawFrame = true;
                $out .= $segment;

                continue;
            }
            // Kept: quantisation and Huffman tables, restart interval and the scan itself. The Adobe colour-transform marker is REBUILT from its one validated value, never copied.
            // Everything else (APPn, COM, ...) is dropped.
            if ($marker === 0xEE && str_starts_with($body, 'Adobe')) {
                if ($size !== 14 || ord($body[11]) > 2) {
                    throw self::refuse('The JPEG is damaged.', 'logo_damaged');
                }
                $out .= "\xFF\xEE\x00\x0E".'Adobe'."\x00\x64\x00\x00\x00\x00".$body[11];
            } elseif (in_array($marker, [0xDB, 0xC4, 0xDD, 0xDA], true)) {
                if ($marker === 0xDD && $size !== 4) {
                    throw self::refuse('The JPEG is damaged.', 'logo_damaged');
                }
                $tables['quant'] = $tables['quant'] || $marker === 0xDB;
                $tables['huffman'] = $tables['huffman'] || $marker === 0xC4;
                $out .= $segment;
            }
            if ($marker === 0xDA) {
                $selectors = ord($body[0] ?? "\0");
                if (! $sawFrame || ! $tables['quant'] || ! $tables['huffman'] || $selectors < 1 || $selectors > $components || $size !== 6 + 2 * $selectors) {
                    throw self::refuse('The JPEG is damaged.', 'logo_damaged');
                }
                $scans++;
                $start = $pos;
                while ($pos + 1 < $length) {
                    if ($bytes[$pos] === "\xFF") {
                        $next = ord($bytes[$pos + 1]);
                        if ($next !== 0x00 && ! ($next >= 0xD0 && $next <= 0xD7) && $next !== 0xFF) {
                            break;
                        }
                    }
                    $pos++;
                }
                $out .= substr($bytes, $start, $pos - $start);
            }
        }
        if (! $ended || $width < 1 || $height < 1 || $scans < 1) {
            throw self::refuse('The JPEG is damaged.', 'logo_damaged');
        }
        $out .= "\xFF\xD9";

        return ['bytes' => $out, 'media_type' => self::JPEG, 'width' => $width, 'height' => $height];
    }
}
