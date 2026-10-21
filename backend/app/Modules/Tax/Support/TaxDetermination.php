<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

/** Support means classification-only determination, never a calculated amount or Party exemption. */
final readonly class TaxDetermination
{
    public function __construct(
        public TaxSupport $support,
        public TaxDisposition $disposition,
        public string $reason,
    ) {}
}
