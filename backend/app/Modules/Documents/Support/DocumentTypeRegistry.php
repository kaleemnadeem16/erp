<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** Trusted registry of adapters. A client never names a class; an unknown or incompatible type/version is refused. */
final class DocumentTypeRegistry
{
    /** @var array<string, DocumentTypeAdapter> */
    private array $types = [];

    public function register(DocumentTypeAdapter $adapter): void
    {
        $key = $adapter->typeKey();
        if (preg_match('/^[a-z0-9]+(\.[a-z0-9-]+)+$/', $key) !== 1 || strlen($key) > 120) {
            throw new DocumentException("'{$key}' is not a valid document type key.", 'type_key', 'type_invalid');
        }
        if ($adapter->schemaVersion() < 1 || $adapter->audiences() === []) {
            throw new DocumentException("Document type '{$key}' needs a schema version and at least one audience.", 'type_key', 'type_invalid');
        }
        if (isset($this->types[$key])) {
            throw new DocumentException("Document type '{$key}' is already registered.", 'type_key', 'type_duplicate');
        }
        $this->types[$key] = $adapter;
    }

    public function registerProvider(DocumentProvider $provider): void
    {
        foreach ($provider->documentTypes() as $adapter) {
            $this->register($adapter);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key, int $schemaVersion): DocumentTypeAdapter
    {
        $adapter = $this->types[$key] ?? throw new DocumentException("No adapter is registered for document type '{$key}'.", 'type_key', 'adapter_missing');
        if ($adapter->schemaVersion() !== $schemaVersion) {
            throw new DocumentException("Document type '{$key}' does not support schema version {$schemaVersion}.", 'schema_version', 'schema_incompatible');
        }

        return $adapter;
    }

    /** What this type allows its configuration to be: its own constraints if it declares them, otherwise the fail-safe A4-only default. */
    public function layoutConstraints(string $key): LayoutConstraints
    {
        $adapter = $this->types[$key] ?? throw new DocumentException("No adapter is registered for document type '{$key}'.", 'type_key', 'adapter_missing');

        return $adapter instanceof ConstrainsDocumentLayout ? $adapter->layoutConstraints() : LayoutConstraints::defaultFor($adapter);
    }

    /** @return array<string, DocumentTypeAdapter> */
    public function all(): array
    {
        ksort($this->types);

        return $this->types;
    }
}
