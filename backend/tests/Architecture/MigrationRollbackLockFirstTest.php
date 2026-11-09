<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A `down()` that refuses to destroy evidence must take its exclusive table locks BEFORE it looks at the rows: checking first leaves a window in which a writer adds evidence
 * between the check and the drop. (docs/architecture/database.md, migration standards, rule 5.)
 */
class MigrationRollbackLockFirstTest extends TestCase
{
    public function test_every_evidence_refusing_down_locks_before_it_reads(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
            $verdict = $this->verdict((string) file_get_contents($file));
            if ($verdict !== null) {
                $offenders[] = basename($file).': '.$verdict;
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_the_rule_catches_a_check_before_the_lock_and_a_missing_lock(): void
    {
        $late = "public function down(): void\n{\n if (DB::table('t')->exists()) { throw new RuntimeException('x'); }\n DB::statement('LOCK TABLE t IN ACCESS EXCLUSIVE MODE');\n}";
        $none = "public function down(): void\n{\n if (DB::table('t')->exists()) { throw new RuntimeException('x'); }\n}";
        $good = "public function down(): void\n{\n DB::statement('LOCK TABLE t IN ACCESS EXCLUSIVE MODE');\n if (DB::table('t')->exists()) { throw new RuntimeException('x'); }\n}";
        $this->assertNotNull($this->verdict($late));
        $this->assertNotNull($this->verdict($none));
        $this->assertNull($this->verdict($good));
    }

    private function verdict(string $source): ?string
    {
        $start = strpos($source, 'function down');
        if ($start === false) {
            return null;
        }
        $down = substr($source, $start);
        if (! str_contains($down, 'RuntimeException')) {
            return null;
        }
        $lock = strpos($down, 'LOCK TABLE');
        $read = strpos($down, 'DB::table(');
        if ($lock === false) {
            return 'down() refuses on evidence but never takes a table lock';
        }

        return $read !== false && $read < $lock ? 'down() reads rows before it takes the lock' : null;
    }
}
