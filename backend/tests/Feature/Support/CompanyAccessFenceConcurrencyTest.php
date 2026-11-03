<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/** Real opposing sessions: demonstrate the forbidden upgrade, then the supported exclusive-first order. */
class CompanyAccessFenceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

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

    private function independentSession(): PDO
    {
        $config = config('database.connections.testing');
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET lock_timeout = '5s'");
        $pdo->exec("SET statement_timeout = '10s'");
        $pdo->exec("SET deadlock_timeout = '100ms'");

        return $pdo;
    }

    /** @return list<string> SQLSTATE or ok for the two transactions */
    private function contend(bool $upgrade): array
    {
        DB::table('company_access_generations')->insertOrIgnore(['company_id' => 1]);
        $parent = $this->independentSession();
        $parent->beginTransaction();
        $parent->query('SELECT company_id FROM company_access_generations ORDER BY company_id FOR '.($upgrade ? 'SHARE' : 'UPDATE'))->fetchAll();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$parentSocket, $childSocket] = $pair;
        stream_set_timeout($parentSocket, 10);
        stream_set_timeout($childSocket, 10);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            try {
                $child = $this->independentSession();
                $child->beginTransaction();
                if ($upgrade) {
                    $child->query('SELECT company_id FROM company_access_generations ORDER BY company_id FOR SHARE')->fetchAll();
                }
                fwrite($childSocket, "ready\n");
                if (trim((string) fgets($childSocket)) !== 'go') {
                    throw new \RuntimeException('Parent did not release the start barrier.');
                }
                if (! $upgrade) {
                    $child->query('SELECT company_id FROM company_access_generations ORDER BY company_id FOR UPDATE')->fetchAll();
                }
                $child->exec('UPDATE company_access_generations SET generation = generation + 1 WHERE company_id = 1');
                $child->commit();
                $state = 'ok';
            } catch (PDOException $exception) {
                $state = (string) $exception->getCode();
                if (isset($child) && $child->inTransaction()) {
                    $child->rollBack();
                }
            } catch (\Throwable $exception) {
                $state = 'error: '.$exception->getMessage();
            }
            fwrite($childSocket, $state."\n");
            fflush($childSocket);
            // Do not run inherited PDO destructors against the parent's sockets.
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($childSocket);
        try {
            $this->assertSame('ready', trim((string) fgets($parentSocket)));
            fwrite($parentSocket, "go\n");
            try {
                // The ordered writer holds the fence while the second waits. The upgrade
                // case holds SHARE in both sessions before either requests UPDATE.
                if (! $upgrade) {
                    usleep(50000);
                }
                $parent->exec('UPDATE company_access_generations SET generation = generation + 1 WHERE company_id = 1');
                $parent->commit();
                $state = 'ok';
            } catch (PDOException $exception) {
                $state = (string) $exception->getCode();
                if ($parent->inTransaction()) {
                    $parent->rollBack();
                }
            }
            $childState = trim((string) fgets($parentSocket));
            pcntl_waitpid($pid, $status);

            return [$state, $childState];
        } finally {
            if ($parent->inTransaction()) {
                $parent->rollBack();
            }
            fclose($parentSocket);
        }
    }

    public function test_two_shared_readers_upgrading_really_deadlock_and_postgres_aborts_one(): void
    {
        $states = $this->contend(true);
        sort($states);
        $this->assertSame(['40P01', 'ok'], $states);
        $this->assertSame(1, (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation'), 'the aborted transaction commits no increment');
    }

    public function test_exclusive_first_writers_finish_without_upgrade_deadlock(): void
    {
        $this->assertSame(['ok', 'ok'], $this->contend(false));
        $this->assertSame(2, (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation'));
    }
}
