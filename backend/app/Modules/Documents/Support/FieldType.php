<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use DateTimeImmutable;

/** The only value kinds a projection may carry. Money and quantities are decimal strings, never floats (ADR 0012). */
enum FieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case Boolean = 'boolean';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Integer => is_int($value),
            self::Decimal => is_string($value) && preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $value) === 1,
            self::Date => is_string($value) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false && $d->format('Y-m-d') === $value,
            self::Boolean => is_bool($value),
        };
    }
}
