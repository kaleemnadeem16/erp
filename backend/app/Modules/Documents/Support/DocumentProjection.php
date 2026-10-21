<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** An owner's typed, authorised projection of a document, addressed by registered type and schema version. */
final readonly class DocumentProjection
{
    /** @param array<string, mixed> $payload */
    public function __construct(public string $typeKey, public int $schemaVersion, public array $payload) {}

    /** Stable across key order: the same facts always yield the same digest. */
    public function digest(): string
    {
        return hash('sha256', json_encode($this->canonical($this->payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map($this->canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
