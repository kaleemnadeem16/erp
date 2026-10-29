<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/** Where a blob's object lives on its disk and what is actually stored there (hash and size, read in one streamed pass; never loaded whole into memory). */
final class StoredObject
{
    public static function path(string $storageKey): string
    {
        return substr($storageKey, 0, 2).'/'.$storageKey;
    }

    /** @return array{sha256: string, size: int}|null null when the object is not there or cannot be read */
    public static function observe(string $disk, string $path): ?array
    {
        try {
            $store = Storage::disk($disk);
            if (! $store->exists($path)) {
                return null;
            }
            $stream = $store->readStream($path);
            if ($stream === null) {
                return null;
            }
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    return null;
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
            fclose($stream);

            return ['sha256' => hash_final($hash), 'size' => $size];
        } catch (Throwable) {
            return null;
        }
    }
}
