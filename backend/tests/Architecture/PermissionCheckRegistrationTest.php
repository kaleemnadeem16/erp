<?php

namespace Tests\Architecture;

use App\Support\PermissionRegistry;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Guards docs/architecture/authorization.md's "Registration and maintenance
 * standard", control 1: every literal permission string checked by backend
 * code must exist in PermissionRegistry. Spatie treats an unregistered
 * permission name as simply never granted, so a typo or a forgotten
 * registration silently denies everyone instead of failing loudly — this
 * test is what turns that into a fast, obvious failure.
 */
class PermissionCheckRegistrationTest extends TestCase
{
    private const SINK_PATTERN = '/(?:->(?:can|authorize|hasPermissionTo|hasAnyPermission|hasAllPermissions)|Gate::(?:allows|denies|authorize|check|any|none))\s*\(/';

    private const PERMISSION_LITERAL_PATTERN = '/[\'"]([a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+)[\'"]/';

    private const VARIABLE_PATTERN = '/\$[A-Za-z_][A-Za-z0-9_]*/';

    public function test_every_literal_permission_check_in_application_code_is_registered(): void
    {
        $registered = app(PermissionRegistry::class)->all();
        $checked = $this->permissionChecksIn(base_path('app'));

        $this->assertNotEmpty($checked, 'Expected to find permission checks under app/ — did the scan break?');

        $unregistered = array_diff(array_keys($checked), $registered);

        $this->assertSame([], $unregistered, 'Backend code checks permission(s) that PermissionRegistry does not '
            .'register: '.implode(', ', array_map(
                static fn (string $permission): string => "{$permission} ({$checked[$permission]})",
                $unregistered
            )));
    }

    public function test_it_catches_a_deliberately_unregistered_permission_check(): void
    {
        $dir = sys_get_temp_dir().'/permission-architecture-test-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/Offender.php', <<<'PHP'
            <?php
            final class Offender
            {
                public function handle($actor): bool
                {
                    return $actor->can('totally.unregistered.permission');
                }
            }
            PHP);

        try {
            $checked = $this->permissionChecksIn($dir);
        } finally {
            unlink($dir.'/Offender.php');
            rmdir($dir);
        }

        $this->assertArrayHasKey('totally.unregistered.permission', $checked);
    }

    public function test_it_resolves_a_permission_named_by_a_local_variable(): void
    {
        $dir = sys_get_temp_dir().'/permission-architecture-test-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/Indirect.php', <<<'PHP'
            <?php
            final class Indirect
            {
                public function handle($actor, bool $immediate): bool
                {
                    $permission = $immediate ? 'namespaced.indirect.override' : 'namespaced.indirect.request';

                    return $actor->can($permission);
                }
            }
            PHP);

        try {
            $checked = $this->permissionChecksIn($dir);
        } finally {
            unlink($dir.'/Indirect.php');
            rmdir($dir);
        }

        $this->assertArrayHasKey('namespaced.indirect.override', $checked);
        $this->assertArrayHasKey('namespaced.indirect.request', $checked);
    }

    /**
     * Scans every .php file under $directory for literal permission strings
     * passed to a known authorisation sink, directly or through a single
     * local variable assigned nearby in the same file (the one indirection
     * pattern actually used in this codebase — see
     * RequestNegativeStockOverride::__invoke).
     *
     * @return array<string, string> permission name => "file:line" reference
     */
    private function permissionChecksIn(string $directory): array
    {
        $found = [];

        foreach (Finder::create()->files()->name('*.php')->in($directory) as $file) {
            $source = $file->getContents();

            if (! preg_match_all(self::SINK_PATTERN, $source, $sinkMatches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($sinkMatches[0] as [$matchText, $offset]) {
                $openParen = $offset + strlen($matchText) - 1;
                $argsText = $this->balancedArgs($source, $openParen);

                foreach ($this->literalsIn($argsText, $source) as $literal) {
                    if (isset($found[$literal])) {
                        continue;
                    }

                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $found[$literal] = $this->reference($file).":{$line}";
                }
            }
        }

        return $found;
    }

    private function balancedArgs(string $source, int $openParen): string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $openParen; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openParen + 1, $i - $openParen - 1);
                }
            }
        }

        return substr($source, $openParen + 1);
    }

    /** @return list<string> */
    private function literalsIn(string $argsText, string $wholeSource): array
    {
        if (preg_match_all(self::PERMISSION_LITERAL_PATTERN, $argsText, $matches)) {
            return $matches[1];
        }

        if (! preg_match_all(self::VARIABLE_PATTERN, $argsText, $variableMatches)) {
            return [];
        }

        $literals = [];

        foreach (array_unique($variableMatches[0]) as $variable) {
            $escaped = preg_quote($variable, '/');

            if (preg_match_all('/'.$escaped.'\s*=(?!=)(.*?);/s', $wholeSource, $assignments)) {
                foreach ($assignments[1] as $rhs) {
                    if (preg_match_all(self::PERMISSION_LITERAL_PATTERN, $rhs, $rhsMatches)) {
                        array_push($literals, ...$rhsMatches[1]);
                    }
                }
            }
        }

        return array_unique($literals);
    }

    private function reference(SplFileInfo $file): string
    {
        return str_starts_with($file->getPathname(), base_path().'/')
            ? $file->getRelativePathname()
            : $file->getFilename();
    }
}
