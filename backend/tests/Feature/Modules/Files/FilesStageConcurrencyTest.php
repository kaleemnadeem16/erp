<?php

namespace Tests\Feature\Modules\Files;

use App\Models\User;
use App\Modules\Files\Actions\RequestFileUpload;
use App\Modules\Files\Actions\StageUploadedFile;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOwnerRegistry;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files\TestFiles;
use Tests\Support\Files\TestOwnerAdapter;
use Tests\TestCase;

/**
 * Files F0 — two genuinely separate OS processes (pcntl_fork, the repository's concurrency technique) stage the SAME upload request at once on a REAL disk directory
 * (committed fixtures, each process on its own connection). Exactly one blob, one stored object and one STAGED event may result; a loser with the same bytes resolves
 * the winner's blob, and a loser with other bytes is refused — and no loser leaves an object behind. Wiped by migrate:fresh afterwards.
 */
class FilesStageConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $root;

    private User $user;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/files_race_'.getmypid().'_'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        config(['filesystems.disks.files.root' => $this->root]);
        Storage::forgetDisk('files');
        $this->user = User::factory()->create();
        $registry = new FileOwnerRegistry;
        $registry->register(new TestOwnerAdapter);
        $this->app->instance(FileOwnerRegistry::class, $registry);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    /** @return list<string> */
    private function objects(): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $found[] = $file->getPathname();
        }

        return $found;
    }

    /** @return array{child: array<string, mixed>, parent: array<string, mixed>} */
    private function race(Closure $childWork, Closure $parentWork): array
    {
        $resultPath = sys_get_temp_dir().'/files_race_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/files_race_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);
        $outcome = static function (Closure $work): array {
            try {
                $result = $work();

                return ['ok' => true, 'blob' => $result['blob']->id, 'replayed' => $result['replayed']];
            } catch (FileException $e) {
                return ['ok' => false, 'reason' => $e->reasonCode];
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'ERROR: '.$e->getMessage()];
            }
        };
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'unable to fork');
            if ($pid === 0) {
                config(['database.connections.files_race_child' => config('database.connections.testing'), 'database.default' => 'files_race_child']);
                for ($waited = 0; ! file_exists($startPath) && $waited < 3_000_000; $waited += 1000) {
                    usleep(1000);
                }
                file_put_contents($resultPath, json_encode($outcome($childWork)));
                posix_kill(getmypid(), SIGKILL);
            }
            $default = config('database.default');
            config(['database.connections.files_race_parent' => config('database.connections.testing'), 'database.default' => 'files_race_parent']);
            file_put_contents($startPath, '1');
            try {
                $parent = $outcome($parentWork);
            } finally {
                config(['database.default' => $default]);
                DB::purge('files_race_parent');
            }
            pcntl_waitpid($pid, $status);
            $child = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($child, 'the forked child did not report an outcome');

            return ['child' => $child, 'parent' => $parent];
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
        }
    }

    private function intent(): int
    {
        return app(RequestFileUpload::class)->execute(TestOwnerAdapter::KEY, 'company:1', TestOwnerAdapter::LOGO, strlen(TestFiles::png()), 'race-'.bin2hex(random_bytes(3)), $this->user->id)['intent']->id;
    }

    private function stage(int $intent, string $bytes): Closure
    {
        return fn () => app(StageUploadedFile::class)->execute($intent, TestFiles::stream($bytes), 'logo.png', 'image/png', $this->user->id);
    }

    public function test_two_processes_staging_the_same_bytes_produce_one_blob_and_one_object(): void
    {
        $intent = $this->intent();
        $result = $this->race($this->stage($intent, TestFiles::png()), $this->stage($intent, TestFiles::png()));

        $this->assertTrue($result['child']['ok'] && $result['parent']['ok'], json_encode($result));
        $this->assertSame($result['child']['blob'], $result['parent']['blob'], 'both resolve the one blob');
        $this->assertSame(1, count(array_filter([$result['child']['replayed'], $result['parent']['replayed']])), 'exactly one of them created it');
        $this->assertSame([1, 1, 'STAGED'], [DB::table('file_blobs')->count(), DB::table('file_blob_events')->count(), DB::table('file_upload_intents')->value('state')]);
        $this->assertCount(1, $this->objects(), 'the loser removed its object');
        $key = (string) DB::table('file_blobs')->value('storage_key');
        $this->assertSame($this->root.'/'.substr($key, 0, 2).'/'.$key, $this->objects()[0]);
        $this->assertSame(TestFiles::png(), file_get_contents($this->objects()[0]));
        $mode = fileperms($this->objects()[0]) & 0777;
        $this->assertSame(0, $mode & 0007, 'the stored object is not world-accessible');
    }

    public function test_two_processes_staging_different_bytes_produce_exactly_one_winner(): void
    {
        $intent = $this->intent();
        $other = substr(TestFiles::png(), 0, -1).'X';
        $result = $this->race($this->stage($intent, TestFiles::png()), $this->stage($intent, $other));

        $outcomes = [$result['child'], $result['parent']];
        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['ok']), 'exactly one stage wins: '.json_encode($result));
        $loser = array_values(array_filter($outcomes, fn ($o) => ! $o['ok']))[0];
        $this->assertSame('intent_already_staged', $loser['reason']);
        $this->assertSame([1, 1], [DB::table('file_blobs')->count(), DB::table('file_blob_events')->count()]);
        $this->assertCount(1, $this->objects(), 'no losing object was left behind');
    }
}
