<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** Port: scans one stored object. The default (NotConfiguredScanner) scans nothing and says so; a real scanner is an activation condition for externally supplied files (D3). */
interface FileScanner
{
    public function scan(string $disk, string $storageKey): ScanResult;
}
