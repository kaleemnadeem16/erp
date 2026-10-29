<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use finfo;

/**
 * Server-side checks on an upload, in a private temporary copy: the byte ceiling is enforced while the stream is read (an oversize upload stops there), the type is
 * sniffed from the bytes and must be on the purpose's closed allowlist, the extension and any declared type must agree with it, no extension in a dotted name may carry
 * code, and an image must really decode to its dimensions within the pixel ceiling. The client's name and type are claims, never trusted; no content, name or path is
 * put in a message.
 */
final class FileValidator
{
    private const CHUNK = 65536;

    /** @param resource $stream */
    public function inspect($stream, FilePurpose $purpose, ?string $declaredName = null, ?string $declaredMediaType = null): ValidatedUpload
    {
        $temp = tmpfile();
        if ($temp === false) {
            throw new FileException('The upload could not be staged.', null, 'staging_unavailable');
        }
        $path = (string) stream_get_meta_data($temp)['uri'];
        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                throw new FileException('The upload could not be read.', null, 'upload_unreadable');
            }
            if ($chunk === '') {
                continue;
            }
            $size += strlen($chunk);
            if ($size > $purpose->maxBytes) {
                throw new FileException("The file is larger than the {$purpose->maxBytes} bytes this purpose accepts.", 'file', 'size_exceeded');
            }
            hash_update($hash, $chunk);
            fwrite($temp, $chunk);
        }
        fflush($temp);
        if ($size === 0) {
            throw new FileException('The file is empty.', 'file', 'file_empty');
        }
        $sha256 = hash_final($hash);
        $detected = strtolower(trim(explode(';', (string) (new finfo(FILEINFO_MIME_TYPE))->file($path))[0]));
        $reject = static fn (string $message, string $field, string $code): FileRejection => new FileRejection($message, $field, $code, $sha256, $size, $detected === '' ? 'application/octet-stream' : $detected);

        if (! isset($purpose->mediaTypes[$detected])) {
            throw $reject('That kind of file is not accepted for this purpose.', 'file', 'media_type_not_allowed');
        }
        if ($declaredMediaType !== null && strtolower(trim(explode(';', $declaredMediaType)[0])) !== $detected) {
            throw $reject('The declared file type does not match the file.', 'file', 'declared_type_mismatch');
        }
        if ($declaredName !== null) {
            $parts = explode('.', self::sanitiseName($declaredName));
            array_shift($parts);
            foreach ($parts as $part) {
                if (in_array(strtolower($part), FilePurpose::FORBIDDEN_EXTENSIONS, true)) {
                    throw $reject('The file name carries an extension that can hold code.', 'file', 'extension_forbidden');
                }
            }
            if ($parts === [] || ! in_array(strtolower((string) end($parts)), $purpose->mediaTypes[$detected], true)) {
                throw $reject('The file name extension does not match the file.', 'file', 'extension_mismatch');
            }
        }
        if ($purpose->isImage($detected)) {
            $image = @getimagesize($path);
            if ($image === false || $image['mime'] !== $detected || $image[0] < 1 || $image[1] < 1) {
                throw $reject('The image could not be decoded.', 'file', 'image_invalid');
            }
            if ($purpose->maxPixels < $image[0] * $image[1]) {
                throw $reject('The image has more pixels than this purpose accepts.', 'file', 'image_too_large');
            }
        }
        rewind($temp);

        return new ValidatedUpload($temp, $sha256, $size, $detected, $declaredMediaType === null ? null : strtolower(trim(explode(';', $declaredMediaType)[0])));
    }

    /** Display-safe name: no path, no control characters, bounded. It is never used to build a storage path. */
    public static function sanitiseName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name, " .\t");

        return mb_substr($name === '' ? 'file' : $name, 0, 200);
    }
}
