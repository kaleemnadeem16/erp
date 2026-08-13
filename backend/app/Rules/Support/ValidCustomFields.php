<?php

declare(strict_types=1);

namespace App\Rules\Support;

use App\Models\CustomFieldDefinition;
use App\Support\CustomFieldDataType;
use Closure;
use DateTime;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a `custom_fields` array against the definitions filed for one entity
 * type — see App\Support\Concerns\HasCustomFields and
 * docs/architecture/extensibility.md#runtime-custom-fields. Every key must have a
 * matching definition (a field must be defined before it can be used), definitions
 * marked required must be present, and each value must match its definition's
 * data_type.
 */
class ValidCustomFields implements ValidationRule
{
    public function __construct(private readonly string $entityType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail("The {$attribute} field must be an array.");

            return;
        }

        $definitions = CustomFieldDefinition::query()
            ->forEntityType($this->entityType)
            ->get()
            ->keyBy('code');

        foreach ($definitions as $code => $definition) {
            if ($definition->is_required && ! array_key_exists($code, $value)) {
                $fail("The {$attribute}.{$code} field is required.");
            }
        }

        foreach ($value as $code => $fieldValue) {
            $definition = $definitions->get($code);

            if ($definition === null) {
                $fail("The {$attribute}.{$code} field has no matching custom field definition for [{$this->entityType}].");

                continue;
            }

            if (! $this->matchesDataType($fieldValue, $definition)) {
                $fail("The {$attribute}.{$code} field does not match its custom field type [{$definition->data_type->value}].");
            }
        }
    }

    private function matchesDataType(mixed $value, CustomFieldDefinition $definition): bool
    {
        return match ($definition->data_type) {
            CustomFieldDataType::String => is_string($value),
            CustomFieldDataType::Number => is_int($value) || is_float($value),
            CustomFieldDataType::Bool => is_bool($value),
            CustomFieldDataType::Date => is_string($value) && $this->isValidDate($value),
            CustomFieldDataType::Select => is_string($value) && in_array($value, $definition->options['choices'] ?? [], true),
            CustomFieldDataType::Reference => is_int($value),
        };
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
