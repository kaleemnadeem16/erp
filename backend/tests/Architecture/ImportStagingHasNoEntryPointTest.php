<?php

namespace Tests\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The import-staging Actions (profiles, staging a file's rows, validating them) take an actor but check no capability, because today
 * nothing outside tests and the demo seeder can call them: there is no controller, console command or job. That is only safe while
 * it stays true. The moment one is added, the entry point needs its own capability (and, for staged rows that carry prices or
 * costs, a field policy on any read) — see docs/architecture/access-security-audit.md, Imports. This test makes that moment loud.
 */
class ImportStagingHasNoEntryPointTest extends TestCase
{
    private const ACTIONS = ['StageImportBatch', 'ValidateImportBatch', 'CreateImportProfile'];

    public function test_no_http_console_or_job_code_calls_the_ungated_import_staging_actions(): void
    {
        $callers = [];
        $finder = (new Finder)->files()->in(base_path('app'))->name('*.php')->notPath('Modules/Imports');
        foreach ($finder as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());
            $entry = str_contains($path, '/Http/') || str_starts_with($path, 'app/Http/') || str_starts_with($path, 'app/Console/') || str_contains($path, '/Jobs/') || str_contains($path, '/Listeners/');
            if (! $entry) {
                continue;
            }
            foreach (self::ACTIONS as $action) {
                if (str_contains((string) file_get_contents($file->getPathname()), $action)) {
                    $callers[] = "{$path} calls {$action}";
                }
            }
        }

        $this->assertSame([], $callers, 'An entry point now reaches the import staging Actions, which check no capability. Give the entry point (and the Actions) a capability and a staged-row field policy, then update the audit tracker and this test.');
    }
}
