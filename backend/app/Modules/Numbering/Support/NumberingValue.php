<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** One value a document supplies: the text, and the record it came from (kept in the allocation log). */
final class NumberingValue
{
    public function __construct(public readonly string $value, public readonly ?string $source = null) {}
}
