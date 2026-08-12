<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsActivityWithDefaults;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base class for domain models. Extend this (instead of Eloquent's Model directly)
 * to get activity logging with the app's default LogOptions for free — see
 * App\Models\Concerns\LogsActivityWithDefaults.
 *
 * Models that can't extend this because they already extend a different framework
 * base class (User extends Authenticatable, for example) use the trait directly.
 */
abstract class Model extends EloquentModel
{
    use LogsActivityWithDefaults;
}
