<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use Illuminate\Support\ServiceProvider;

/**
 * The disabled Jobs foundation (SP0b; docs/phases/phase-5-sp0a-design.md). It binds nothing, registers no route, permission vocabulary or duty, and has no
 * entry point that creates a Job. Later slices add the receiver, lifecycle and their guarded Actions here.
 */
class JobsServiceProvider extends ServiceProvider {}
