<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** The central document-type registry. Modules register their types in their service provider. */
final class NumberingRegistry
{
    /** @var array<string, NumberableDocumentType> */
    private array $types = [];

    public function register(NumberableDocumentType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): NumberableDocumentType
    {
        return $this->types[$key] ?? throw new NumberingException("'{$key}' is not a registered numbered document type.", 'document_type');
    }

    /** @return array<string, NumberableDocumentType> */
    public function all(): array
    {
        ksort($this->types);

        return $this->types;
    }
}
