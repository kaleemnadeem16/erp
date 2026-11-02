<?php

namespace Tests\Feature\Support;

use App\Modules\Files\Support\StoredObject;
use App\Support\Logging\RedactStorageLocations;
use App\Support\Logging\RedactStorageLocationsTap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use League\Flysystem\UnableToReadFile;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** #73: a stored file's key, its object path and the files disk root never reach a log, whichever channel writes it. */
class RedactStorageLocationsTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef01234567';

    public function test_message_context_and_exceptions_are_redacted(): void
    {
        $root = (string) config('filesystems.disks.files.root');
        $path = StoredObject::path(self::KEY);
        $redactor = new RedactStorageLocations;
        $flysystem = UnableToReadFile::fromLocation($path, 'disk full');
        $query = new QueryException('pgsql', 'insert into file_blobs (storage_key) values (?)', [self::KEY], new PDOException('duplicate key'));
        $wrapped = new RuntimeException("could not open {$root}/{$path}", 0, $query);

        $record = $redactor(new LogRecord(new \DateTimeImmutable, 'test', Level::Error, "read {$root}/{$path} failed for ".self::KEY, [
            'nested' => ['location' => $path, 'deeper' => ['key' => self::KEY]], 'flysystem' => $flysystem, 'exception' => $wrapped,
        ]));
        $dump = json_encode([$record->message, $record->context], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString(self::KEY, $dump);
        $this->assertStringNotContainsString($path, $dump);
        $this->assertStringNotContainsString($root, $dump);
        $this->assertStringContainsString(RedactStorageLocations::OBJECT, $dump);
        $this->assertStringContainsString(RedactStorageLocations::KEY, $dump, 'the query exception in the previous chain is redacted too');
        $this->assertSame(RuntimeException::class, $record->context['exception']['class'], 'the exception keeps its class and location for diagnosis');
        $this->assertSame(QueryException::class, $record->context['exception']['previous']['class']);
    }

    public function test_every_log_channel_carries_the_redaction_tap(): void
    {
        foreach (config('logging.channels') as $name => $channel) {
            if ($name === 'emergency') {
                continue; // Laravel's last-resort fallback is a bare path, not a configurable channel (no tap support).
            }
            $this->assertContains(RedactStorageLocationsTap::class, $channel['tap'] ?? [], "Log channel '{$name}' must carry the storage redaction tap.");
        }
    }

    public function test_a_real_channel_writes_no_storage_location(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'redact');
        config(['logging.channels.redact-probe' => ['driver' => 'single', 'path' => $file, 'tap' => [RedactStorageLocationsTap::class]]]);
        $path = StoredObject::path(self::KEY);

        Log::channel('redact-probe')->error('Unable to read file at location: '.$path, ['exception' => UnableToReadFile::fromLocation($path)]);

        $written = (string) file_get_contents($file);
        @unlink($file);
        $this->assertStringContainsString('Unable to read file at location', $written);
        $this->assertStringNotContainsString(self::KEY, $written);
        $this->assertStringContainsString(RedactStorageLocations::OBJECT, $written);
    }
}
