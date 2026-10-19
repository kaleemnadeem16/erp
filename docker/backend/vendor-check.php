<?php

// Runs inside a runner container: does the vendor/ tree this checkout is about to use match ITS composer.lock?
// The runners share one read-only vendor volume (the one the dev `app` container installed). A branch that changes
// composer.lock would otherwise run against someone else's dependencies without any sign of it.
// Exit 0 = match, 1 = mismatch (prints the first differences), 2 = cannot tell.
$lockFile = '/var/www/html/composer.lock';
$installedFile = '/var/www/html/vendor/composer/installed.php';
if (! is_file($lockFile) || ! is_file($installedFile)) {
    fwrite(STDERR, "cannot compare: composer.lock or vendor/composer/installed.php is missing\n");
    exit(2);
}
$lock = json_decode((string) file_get_contents($lockFile), true);
$installed = (require $installedFile)['versions'] ?? [];
$problems = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $name = $package['name'];
    $want = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
    $have = $installed[$name]['reference'] ?? null;
    if (! isset($installed[$name])) {
        $problems[] = "$name {$package['version']} is in composer.lock but not installed";
    } elseif ($want !== null && $have !== null && $want !== $have) {
        $problems[] = "$name: composer.lock wants {$package['version']} ($want) but the shared vendor has ".($installed[$name]['pretty_version'] ?? '?')." ($have)";
    }
}
if ($problems !== []) {
    fwrite(STDERR, implode("\n", array_slice($problems, 0, 5)).(count($problems) > 5 ? "\n… and ".(count($problems) - 5).' more' : '')."\n");
    exit(1);
}
exit(0);
