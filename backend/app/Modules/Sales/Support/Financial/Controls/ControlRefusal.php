<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Exceptions\DomainInvariantException;

/**
 * Refusals of malformed control inputs. The messages are fixed text: they never carry a cost, threshold, price or any other protected operand, because a
 * refusal can reach a person who may not read them.
 */
final class ControlRefusal
{
    public const CONTROL_INVALID = 'CONTROL_INVALID';

    public const CONTROL_CONTEXT_MISMATCH = 'CONTROL_CONTEXT_MISMATCH';

    public const FACTS_INVALID = 'NET_FACTS_INVALID';

    public const CONTROL_KIND_NOT_SUPPORTED = 'CONTROL_KIND_NOT_SUPPORTED';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
