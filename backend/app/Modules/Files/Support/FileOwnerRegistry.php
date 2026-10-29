<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** Trusted registry of file owners. A client never names a class; an unknown owner or purpose is refused. Registering grants nothing. */
final class FileOwnerRegistry
{
    /** @var array<string, FileOwnerAdapter> */
    private array $owners = [];

    public function register(FileOwnerAdapter $adapter): void
    {
        $key = $adapter->ownerTypeKey();
        if (preg_match('/^[a-z0-9]+(\.[a-z0-9-]+)+$/', $key) !== 1 || strlen($key) > 120) {
            throw new FileException("'{$key}' is not a valid file owner key.", 'owner_type_key', 'owner_invalid');
        }
        if (isset($this->owners[$key])) {
            throw new FileException("File owner '{$key}' is already registered.", 'owner_type_key', 'owner_duplicate');
        }
        $purposes = [];
        foreach ($adapter->purposes() as $purpose) {
            if (isset($purposes[$purpose->key])) {
                throw new FileException("File purpose '{$purpose->key}' is listed twice for '{$key}'.", 'purpose', 'purpose_duplicate');
            }
            $purposes[$purpose->key] = true;
        }
        if ($purposes === []) {
            throw new FileException("File owner '{$key}' needs at least one purpose.", 'owner_type_key', 'owner_invalid');
        }
        $this->owners[$key] = $adapter;
    }

    public function has(string $ownerTypeKey): bool
    {
        return isset($this->owners[$ownerTypeKey]);
    }

    public function get(string $ownerTypeKey): FileOwnerAdapter
    {
        return $this->owners[$ownerTypeKey] ?? throw new FileException("No file owner is registered for '{$ownerTypeKey}'.", 'owner_type_key', 'owner_missing');
    }

    public function purpose(string $ownerTypeKey, string $purposeKey): FilePurpose
    {
        foreach ($this->get($ownerTypeKey)->purposes() as $purpose) {
            if ($purpose->key === $purposeKey) {
                return $purpose;
            }
        }

        throw new FileException("File owner '{$ownerTypeKey}' does not accept files for '{$purposeKey}'.", 'purpose', 'purpose_unknown');
    }
}
