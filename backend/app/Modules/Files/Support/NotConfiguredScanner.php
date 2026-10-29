<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** The default scanner: it reads nothing, scans nothing and reports NOT_CONFIGURED, so nothing externally supplied can ever become downloadable by default. */
final class NotConfiguredScanner implements FileScanner
{
    public function scan(string $disk, string $storageKey): ScanResult
    {
        return ScanResult::NotConfigured;
    }
}
