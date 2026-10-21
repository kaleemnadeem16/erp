<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The allowlist an adapter declares for its typed projection: header facts and named sections. Shared code validates shape only; it never recomputes
 * totals, quantities or consent (ADR 0137). Anything not declared is refused.
 */
final readonly class ProjectionSchema
{
    /**
     * @param  array<string, FieldSpec>  $header
     * @param  array<string, SectionSchema>  $sections
     */
    public function __construct(public array $header, public array $sections = []) {}

    /** @param array<string, mixed> $payload */
    public function validate(array $payload): void
    {
        $this->refuseUnknown($payload, ['header', 'sections'], 'projection');
        $this->validateFields($this->asMap($payload['header'] ?? null, 'header'), $this->header, 'header');
        $given = $this->asMap($payload['sections'] ?? [], 'sections');
        $this->refuseUnknown($given, array_keys($this->sections), 'sections');
        foreach ($this->sections as $name => $section) {
            if (! array_key_exists($name, $given)) {
                if ($section->required) {
                    throw new DocumentException("Section '{$name}' is required.", "sections.{$name}", 'projection_invalid');
                }

                continue;
            }
            if (! $section->repeating) {
                $this->validateFields($this->asMap($given[$name], "sections.{$name}"), $section->fields, "sections.{$name}");

                continue;
            }
            if (! is_array($given[$name]) || ! array_is_list($given[$name]) || ($section->required && $given[$name] === [])) {
                throw new DocumentException("Section '{$name}' must be a ".($section->required ? 'non-empty ' : '').'list of rows.', "sections.{$name}", 'projection_invalid');
            }
            foreach ($given[$name] as $i => $row) {
                $this->validateFields($this->asMap($row, "sections.{$name}.{$i}"), $section->fields, "sections.{$name}.{$i}");
            }
        }
    }

    /** @return array<string, mixed> */
    private function asMap(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new DocumentException("'{$path}' must be an object.", $path, 'projection_invalid');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $given
     * @param  array<string, FieldSpec>  $specs
     */
    private function validateFields(array $given, array $specs, string $path): void
    {
        $this->refuseUnknown($given, array_keys($specs), $path);
        foreach ($specs as $name => $spec) {
            if (! array_key_exists($name, $given) || $given[$name] === null) {
                if ($spec->required) {
                    throw new DocumentException("'{$path}.{$name}' is required.", "{$path}.{$name}", 'projection_invalid');
                }

                continue;
            }
            if (! $spec->type->accepts($given[$name])) {
                throw new DocumentException("'{$path}.{$name}' must be a valid {$spec->type->value}.", "{$path}.{$name}", 'projection_invalid');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $given
     * @param  list<array-key>  $allowed
     */
    private function refuseUnknown(array $given, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($given), $allowed);
        if ($unknown !== []) {
            throw new DocumentException("'{$path}' carries undeclared key '".reset($unknown)."'.", $path, 'projection_invalid');
        }
    }
}
