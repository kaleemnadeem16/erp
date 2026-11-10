<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The password given to NEW demo accounts that can sign in (issue #100). There is no default in code or config.
 *
 * - `DEMO_USER_PASSWORD` set: that value is used (tests set an explicit value in phpunit.xml).
 * - Not set: one random password is generated per run, kept in the container and printed once by `demo:seed` (see {@see self::generated()}).
 *
 * Existing accounts keep whatever credentials they have: seeders never reset a password.
 */
final class DemoCredentials
{
    private const GENERATED = 'demo.generated_password';

    public static function password(): string
    {
        $configured = config('demo.user.password');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (! app()->bound(self::GENERATED)) {
            app()->instance(self::GENERATED, bin2hex(random_bytes(12)).'Aa1!');
        }

        return (string) app(self::GENERATED);
    }

    /** The password generated in this run, or null when one was configured (or none was needed). */
    public static function generated(): ?string
    {
        return app()->bound(self::GENERATED) ? (string) app(self::GENERATED) : null;
    }
}
