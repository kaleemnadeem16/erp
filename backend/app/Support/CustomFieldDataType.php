<?php

declare(strict_types=1);

namespace App\Support;

/**
 * See docs/architecture/extensibility.md#runtime-custom-fields. Validated against a
 * stored value by App\Rules\Support\ValidCustomFields.
 */
enum CustomFieldDataType: string
{
    case String = 'string';
    case Number = 'number';
    case Date = 'date';
    case Bool = 'bool';
    case Select = 'select';
    case Reference = 'reference';
}
