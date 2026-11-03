<?php

/** Synthetic lock-graph harness; no runtime entry point, authority grant or production-scale claim. */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.testing');
if (! $app->environment('testing') || $config['host'] !== 'postgres-test'
    || ! preg_match('/^erp_testing_[a-z0-9_]{1,40}$/D', $config['database'])
    || DB::connection()->getDatabaseName() !== $config['database']) {
    throw new RuntimeException('Measurement is restricted to a private testing database on postgres-test.');
}
$options = getopt('', ['companies:', 'writers:', 'readers:', 'rows:', 'iterations:', 'hold-ms:', 'lock-timeout-ms:']);
$parameters = [];
foreach (['companies' => 3, 'writers' => 4, 'readers' => 2, 'rows' => 100, 'iterations' => 20, 'hold-ms' => 5, 'lock-timeout-ms' => 1000] as $key => $default) {
    $value = (string) ($options[$key] ?? $default);
    if (! ctype_digit($value) || (int) $value > 100000 || ((int) $value === 0 && ! in_array($key, ['hold-ms', 'readers'], true))) {
        throw new RuntimeException("Invalid --{$key}: expected a positive integer (zero allowed for readers/hold-ms), at most 100000.");
    }
    $parameters[$key] = (int) $value;
}
if ($parameters['writers'] + $parameters['readers'] > 64) {
    throw new RuntimeException('At most 64 workers are supported by this local harness.');
}
$connect = static function () use ($config, $parameters): PDO {
    $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET lock_timeout = '{$parameters['lock-timeout-ms']}ms'");
    $pdo->exec("SET statement_timeout = '30s'");

    return $pdo;
};
$fixtures = [];
for ($i = 0; $i < $parameters['companies']; $i++) {
    $company = DB::table('companies')->insertGetId(['name' => 'Synthetic fence '.$i, 'created_at' => now(), 'updated_at' => now()]);
    $user = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Synthetic actor', 'email' => "fence-{$i}@example.invalid", 'password' => 'unusable-harness-password', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $first = null;
    for ($row = 0; $row < $parameters['rows']; $row++) {
        $family = DB::table('quotation_families')->insertGetId(['company_id' => $company, 'created_by' => $user, 'access_baseline_state' => 'UNRESOLVED', 'created_at' => now(), 'updated_at' => now()]);
        $first ??= $family;
    }
    $fixtures[] = ['company' => $company, 'user' => $user, 'family' => $first];
}
// No child uses the inherited Laravel connection. SIGKILL after flushing its JSON avoids
// inherited PDO destructors closing the parent's socket (the repository's fork-test convention).
$directory = sys_get_temp_dir().'/access-fence-'.bin2hex(random_bytes(10));
mkdir($directory, 0700);
$pids = [];
$started = hrtime(true);
try {
    $workers = $parameters['writers'] + $parameters['readers'] + 1;
    for ($worker = 0; $worker < $workers; $worker++) {
        $mode = $worker < $parameters['writers'] ? 'writer' : ($worker < $workers - 1 ? 'reader' : 'login');
        $fixture = $fixtures[$worker % count($fixtures)];
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork measurement worker.');
        }
        if ($pid === 0) {
            $samples = [];
            try {
                $pdo = $connect();
                $deadline = microtime(true) + 10;
                while (! file_exists($directory.'/start')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Start barrier timed out.');
                    }
                    usleep(1000);
                }
                for ($iteration = 0; $iteration < $parameters['iterations']; $iteration++) {
                    $begin = hrtime(true);
                    $lockMs = null;
                    $state = 'ok';
                    try {
                        $pdo->beginTransaction();
                        if ($mode === 'writer') {
                            $pdo->exec('INSERT INTO company_access_generations(company_id) SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING');
                            $pdo->query('SELECT company_id FROM company_access_generations ORDER BY company_id FOR UPDATE')->fetchAll();
                        } elseif ($mode === 'reader') {
                            $pdo->query("SELECT generation FROM company_access_generations WHERE company_id = {$fixture['company']} FOR SHARE")->fetchAll();
                        }
                        $lockMs = $mode === 'login' ? null : (hrtime(true) - $begin) / 1000000;
                        if ($mode === 'writer') {
                            $pdo->exec("UPDATE quotation_families SET updated_at = updated_at WHERE id = {$fixture['family']}");
                        } elseif ($mode === 'reader') {
                            $pdo->query("SELECT id FROM quotation_families WHERE company_id = {$fixture['company']} ORDER BY id LIMIT 25")->fetchAll();
                        } else {
                            $pdo->exec("UPDATE users SET last_login_at = now() WHERE id = {$fixture['user']}");
                        }
                        usleep($parameters['hold-ms'] * 1000);
                        $pdo->commit();
                    } catch (PDOException $exception) {
                        $state = (string) $exception->getCode();
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                    $samples[] = ['mode' => $mode, 'company' => $fixture['company'], 'state' => $state, 'lock_ms' => $lockMs, 'total_ms' => (hrtime(true) - $begin) / 1000000];
                }
                file_put_contents($directory.'/'.$worker.'.json', json_encode(['samples' => $samples], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($directory.'/'.$worker.'.json', json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            }
            posix_kill(getmypid(), SIGKILL);
        }
        $pids[] = $pid;
    }
    file_put_contents($directory.'/start', '1');
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $samples = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $result = json_decode((string) file_get_contents($directory.'/'.$worker.'.json'), true, flags: JSON_THROW_ON_ERROR);
        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }
        array_push($samples, ...$result['samples']);
    }
    $distribution = static function (array $values): array {
        sort($values);
        $out = ['count' => count($values)];
        foreach (['p50' => 0.50, 'p95' => 0.95, 'p99' => 0.99, 'max' => 1] as $label => $percentile) {
            $out[$label] = $values === [] ? null : round($values[max(0, (int) ceil(count($values) * $percentile) - 1)], 3);
        }

        return $out;
    };
    $summary = [];
    foreach (['writer', 'reader', 'login'] as $mode) {
        $group = array_values(array_filter($samples, fn (array $sample): bool => $sample['mode'] === $mode));
        $summary[$mode] = ['states' => array_count_values(array_column($group, 'state')), 'lock_ms' => $distribution(array_values(array_filter(array_column($group, 'lock_ms'), fn ($value): bool => $value !== null))), 'total_ms' => $distribution(array_column($group, 'total_ms'))];
    }
    echo json_encode(['parameters' => $parameters, 'elapsed_ms' => round((hrtime(true) - $started) / 1000000, 3), 'postgres' => DB::selectOne('SHOW server_version')->server_version, 'summary' => $summary,
        'limits' => 'Single local Docker host; synthetic rows and transaction holds; concurrent independent processes, no real workload profile. Lock timing includes query/client overhead. Raw lock graph only, not API, pricing, issue, document rendering or authorisation throughput. Production activation remains blocked pending owner company counts, workload profile and latency budgets.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    foreach ($pids as $pid) {
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
