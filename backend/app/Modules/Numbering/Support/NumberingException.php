<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use App\Exceptions\DomainInvariantException;

/** Something stopped a number being issued or a configuration being saved; always says why, in words a person can act on. */
final class NumberingException extends DomainInvariantException {}
