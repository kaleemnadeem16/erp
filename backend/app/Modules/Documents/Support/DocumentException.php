<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Exceptions\DomainInvariantException;

/** A document operation was refused (unregistered type, denied access, invalid projection, conflicting replay); always says why. */
final class DocumentException extends DomainInvariantException {}
