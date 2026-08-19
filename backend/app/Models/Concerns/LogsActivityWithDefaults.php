<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\CompanyContext;
use Spatie\Activitylog\Models\Activity;
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

    /**
     * Stamps every activity with the company it happened under, via the same
     * CompanyContext seam BelongsToCompany uses to fill company_id on create. This
     * needs no per-model knowledge of whether the subject carries its own company_id
     * or scopes through a parent (see BelongsToCompanyThroughParent) — see
     * multi-tenancy.md "Polymorphic logs tag via tapActivity".
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        // company_id isn't declared on Spatie's Activity model — it's a column this
        // app's migration adds on top of the package's own schema.
        // @phpstan-ignore property.notFound
        $activity->company_id = app(CompanyContext::class)->id();
    }
}
