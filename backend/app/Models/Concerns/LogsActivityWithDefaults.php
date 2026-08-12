<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Shared spatie/laravel-activitylog defaults: only log attributes that actually
 * changed, skip empty (no-op) log entries, and never log secrets. Models needing
 * different behaviour override getActivitylogOptions() after using this trait.
 */
trait LogsActivityWithDefaults
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept(['password', 'remember_token']);
    }
}
