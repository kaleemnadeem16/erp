<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Exceptions\DomainInvariantException;

/** A file operation was refused (unknown owner or purpose, denied access, invalid upload, conflicting replay); always carries a stable reason code, never content. */
class FileException extends DomainInvariantException {}
