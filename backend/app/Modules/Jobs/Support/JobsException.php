<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support;

use App\Exceptions\DomainInvariantException;

/** A Jobs value was refused; always says which field and why. */
final class JobsException extends DomainInvariantException {}
