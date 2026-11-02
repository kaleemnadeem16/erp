<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/** Attached to every log channel in config/logging.php (`tap`), so no handler formats a record before RedactStorageLocations has run (#73). */
final class RedactStorageLocationsTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactStorageLocations);
        }
    }
}
