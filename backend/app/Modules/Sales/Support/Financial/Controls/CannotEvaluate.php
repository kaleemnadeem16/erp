<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use RuntimeException;

/** Internal signal that a control lacks the evidence to be judged; carries only a fixed reason code, never an operand. */
final class CannotEvaluate extends RuntimeException {}
