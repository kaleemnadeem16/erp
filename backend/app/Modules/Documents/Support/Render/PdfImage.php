<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

use App\Modules\Documents\Support\DocumentException;

/**
 * One raster image prepared for a PDF image XObject, from a logo that Company already sanitised (ADR 0162): a JPEG is embedded as it is (DCTDecode); a PNG without transparency
 * is embedded as its own compressed pixel data with a PNG predictor; a PNG with transparency (alpha channel or a palette with transparent entries) has its colour and alpha
 * separated into an image and a soft mask, which needs the scanlines un-filtered here. Pure PHP, deterministic, nothing external. Anything outside the supported subset is
 * refused with `brand_asset_unsupported`: the caller fails the generation rather than print without the logo it pinned.
 */
final class PdfImage
{
    /**
     * @param  array{width: int, height: int, data: string}|null  $mask  a DeviceGray soft mask, 8 bits, Flate-compressed
     * @param  list<int>|null  $colourKey  PDF /Mask colour-key ranges for a grey or RGB PNG with a tRNS chunk
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $colourSpace,
        public readonly int $bitsPerComponent,
        public readonly string $filter,
        public readonly ?string $decodeParms,
        public readonly string $data,
        public readonly ?array $mask,
        public readonly ?array $colourKey,
    ) {}

    public static function fromBytes(string $mediaType, string $bytes): self
    {
        try {
            return match ($mediaType) {
                'image/jpeg' => self::jpeg($bytes),
                'image/png' => self::png($bytes),
                default => throw new \RuntimeException('type'),
            };
        } catch (DocumentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
        }
    }

    // ---- JPEG ----------------------------------------------------------------------------------------------------------------------------------------

    private static function jpeg(string $b): self
    {
        $length = strlen($b);
        $pos = 2;
        while ($pos + 4 <= $length) {
            if ($b[$pos] !== "\xFF") {
                break;
            }
            $marker = ord($b[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;

                continue;
            }
            $size = unpack('n', substr($b, $pos + 2, 2))[1];
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                $height = unpack('n', substr($b, $pos + 5, 2))[1];
                $width = unpack('n', substr($b, $pos + 7, 2))[1];
                $components = ord($b[$pos + 9]);
                if ($width < 1 || $height < 1 || ! in_array($components, [1, 3], true)) {
                    break;
                }

                return new self($width, $height, $components === 1 ? '/DeviceGray' : '/DeviceRGB', 8, '/DCTDecode', null, $b, null, null);
            }
            $pos += 2 + $size;
        }

        throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
    }

    // ---- PNG -----------------------------------------------------------------------------------------------------------------------------------------

    private static function png(string $b): self
    {
        $length = strlen($b);
        $pos = 8;
        $header = $palette = $trns = null;
        $idat = '';
        while ($pos + 12 <= $length) {
            $size = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $body = substr($b, $pos + 8, $size);
            $pos += 12 + $size;
            match ($type) {
                'IHDR' => $header = $body,
                'PLTE' => $palette = $body,
                'tRNS' => $trns = $body,
                'IDAT' => $idat .= $body,
                default => null,
            };
        }
        if ($header === null || $idat === '' || strlen($header) !== 13) {
            throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
        }
        ['w' => $w, 'h' => $h, 'd' => $depth, 'c' => $colour, 'i' => $interlace] = unpack('Nw/Nh/Cd/Cc/Cm/Cf/Ci', $header);
        if ($interlace !== 0 || $depth === 16 || $w < 1 || $h < 1) {
            throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
        }

        if ($colour === 4 || $colour === 6) {
            return self::withAlpha($w, $h, $colour === 6 ? 4 : 2, $idat);
        }
        $channels = $colour === 2 ? 3 : 1;
        $parms = "<< /Predictor 15 /Colors {$channels} /BitsPerComponent {$depth} /Columns {$w} >>";
        if ($colour === 3) {
            if ($palette === null) {
                throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
            }
            $space = '[/Indexed /DeviceRGB '.(intdiv(strlen($palette), 3) - 1).' <'.strtoupper(bin2hex($palette)).'>]';
            $mask = null;
            if ($trns !== null && preg_match('/[^\xFF]/', $trns) === 1) {
                $mask = self::paletteMask($w, $h, $depth, $idat, $trns);
            }

            return new self($w, $h, $space, $depth, '/FlateDecode', $parms, $idat, $mask, null);
        }
        $key = null;
        if ($trns !== null) {
            $samples = array_values(unpack('n*', $trns));
            $key = [];
            foreach ($samples as $sample) {
                array_push($key, $sample, $sample);
            }
            if (count($key) !== 2 * $channels) {
                $key = null;
            }
        }

        return new self($w, $h, $colour === 2 ? '/DeviceRGB' : '/DeviceGray', $depth, '/FlateDecode', $parms, $idat, null, $key);
    }

    /** Colour and alpha of a grey+alpha or RGBA 8-bit PNG, split into an image and a soft mask. */
    private static function withAlpha(int $w, int $h, int $channels, string $idat): self
    {
        $rows = self::unfilter($w, $h, $channels, 8, $idat);
        $colour = $alpha = '';
        $colourChannels = $channels - 1;
        foreach ($rows as $row) {
            for ($x = 0; $x < $w; $x++) {
                $offset = $x * $channels;
                $colour .= substr($row, $offset, $colourChannels);
                $alpha .= $row[$offset + $colourChannels];
            }
        }

        return new self($w, $h, $colourChannels === 3 ? '/DeviceRGB' : '/DeviceGray', 8, '/FlateDecode', null, (string) gzcompress($colour, 6), ['width' => $w, 'height' => $h, 'data' => (string) gzcompress($alpha, 6)], null);
    }

    /** @return array{width: int, height: int, data: string} */
    private static function paletteMask(int $w, int $h, int $depth, string $idat, string $trns): array
    {
        $rows = self::unfilter($w, $h, 1, $depth, $idat);
        $table = array_values(unpack('C*', $trns));
        $alpha = '';
        $perByte = intdiv(8, $depth);
        $mask = (1 << $depth) - 1;
        foreach ($rows as $row) {
            for ($x = 0; $x < $w; $x++) {
                $byte = ord($row[intdiv($x, $perByte)]);
                $shift = 8 - $depth * (($x % $perByte) + 1);
                $index = ($byte >> $shift) & $mask;
                $alpha .= chr($table[$index] ?? 255);
            }
        }

        return ['width' => $w, 'height' => $h, 'data' => (string) gzcompress($alpha, 6)];
    }

    /** @return list<string> the un-filtered scanlines (without their filter byte) */
    private static function unfilter(int $w, int $h, int $channels, int $depth, string $idat): array
    {
        $rowBytes = intdiv($w * $channels * $depth + 7, 8);
        $raw = gzuncompress($idat, ($rowBytes + 1) * $h + 1);
        if ($raw === false || strlen($raw) !== ($rowBytes + 1) * $h) {
            throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported');
        }
        $bpp = max(1, intdiv($channels * $depth + 7, 8));
        $rows = [];
        $previous = array_fill(0, $rowBytes, 0);
        for ($y = 0; $y < $h; $y++) {
            $filter = ord($raw[$y * ($rowBytes + 1)]);
            $line = array_values(unpack('C*', substr($raw, $y * ($rowBytes + 1) + 1, $rowBytes)));
            $current = array_fill(0, $rowBytes, 0);
            for ($i = 0; $i < $rowBytes; $i++) {
                $left = $i >= $bpp ? $current[$i - $bpp] : 0;
                $up = $previous[$i];
                $upLeft = $i >= $bpp ? $previous[$i - $bpp] : 0;
                $add = match ($filter) {
                    0 => 0,
                    1 => $left,
                    2 => $up,
                    3 => ($left + $up) >> 1,
                    4 => self::paeth($left, $up, $upLeft),
                    default => throw new DocumentException('The pinned logo cannot be drawn.', null, 'brand_asset_unsupported'),
                };
                $current[$i] = ($line[$i] + $add) & 0xFF;
            }
            $rows[] = pack('C*', ...$current);
            $previous = $current;
        }

        return $rows;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }
}
