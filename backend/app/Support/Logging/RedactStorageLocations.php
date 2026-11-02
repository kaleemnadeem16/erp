<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Keeps stored-file locations out of every log record (#73). A Files storage key (40 lowercase hex characters), the object path built from it
 * (`ab/<key>`, StoredObject::path) and the files disk root can reach a log through a storage driver's exception message (Flysystem names the
 * location it could not read) or a query exception (its message carries the SQL and its bindings). The message, every string in the context and
 * any exception in the context (its message, its previous chain and its trace text) are rewritten with fixed placeholders before any handler
 * formats the record. Nothing else changes: the record keeps its level, channel and the shape a reader needs to diagnose the fault.
 */
final class RedactStorageLocations implements ProcessorInterface
{
    public const OBJECT = '[stored-object]';

    public const KEY = '[storage-key]';

    public const ROOT = '[files-root]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->redact($record->message), context: $this->context($record->context), extra: $this->context($record->extra));
    }

    public function redact(string $text): string
    {
        foreach ($this->roots() as $root) {
            $text = str_replace($root, self::ROOT, $text);
        }
        $text = (string) preg_replace('~\b[0-9a-f]{2}/[0-9a-f]{40}\b~', self::OBJECT, $text);

        return (string) preg_replace('~\b[0-9a-f]{40}\b~', self::KEY, $text);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function context(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                is_string($value) => $this->redact($value),
                is_array($value) => $this->context($value),
                $value instanceof Throwable => $this->throwable($value),
                default => $value,
            };
        }

        return $values;
    }

    /** @return array{class: string, message: string, code: int|string, file: string, line: int, trace: string, previous: array<mixed>|null} */
    private function throwable(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $this->redact($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->redact($e->getTraceAsString()),
            'previous' => $e->getPrevious() === null ? null : $this->throwable($e->getPrevious()),
        ];
    }

    /** @return list<string> the configured roots of the files disk, longest first, so a nested root is replaced whole */
    private function roots(): array
    {
        $roots = array_filter([(string) config('filesystems.disks.files.root', ''), (string) realpath((string) config('filesystems.disks.files.root', ''))], fn (string $r): bool => strlen($r) > 1);
        usort($roots, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique($roots));
    }
}
