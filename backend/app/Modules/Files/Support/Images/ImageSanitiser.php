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

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** @return array{bytes: string, media_type: string, width: int, height: int} */
    public static function sanitise(string $bytes): array
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return self::png($bytes);
        }
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($bytes);
        }

        throw self::refuse('Only PNG and JPEG logos are accepted.', 'logo_type_unsupported');
    }

    private static function refuse(string $message, string $code): FileException
    {
        return new FileException($message, 'logo', $code);
    }

    // ---- PNG -----------------------------------------------------------------------------------------------------------------------------------------

    /** @return array{bytes: string, media_type: string, width: int, height: int} */
    private static function png(string $bytes): array
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
        $raw = @gzuncompress($data, $expected + 1);
        if ($raw === false || strlen($raw) !== $expected) {
            throw self::refuse('The PNG image data is damaged.', 'logo_damaged');
        }
        $out = self::PNG_SIGNATURE.self::chunk('IHDR', $header);
        if ($colour === 3 && $palette !== null) {
            $out .= self::chunk('PLTE', $palette);
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
    private static function jpeg(string $bytes): array
    {
        $length = strlen($bytes);
        $pos = 2;
        $out = "\xFF\xD8";
        $width = $height = 0;
        $scans = 0;
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
                if ($size < 8 || ord($body[0]) !== 8) {
                    throw self::refuse('This kind of JPEG is not supported.', 'logo_unsupported_jpeg');
                }
                [$height, $width] = array_values(unpack('n2', substr($body, 1, 4)));
                $components = ord($body[5]);
                if (! in_array($components, [1, 3], true)) {
                    throw self::refuse('CMYK JPEG files are not supported; export the logo as RGB.', 'logo_cmyk');
                }
                $out .= $segment;

                continue;
            }
            // Kept: quantisation and Huffman tables, restart interval, the scan itself and the small Adobe colour-transform marker. Everything else (APPn, COM, ...) is dropped.
            $keep = in_array($marker, [0xDB, 0xC4, 0xDD, 0xDA], true) || ($marker === 0xEE && str_starts_with($body, 'Adobe'));
            if ($keep) {
                $out .= $segment;
            }
            if ($marker === 0xDA) {
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
