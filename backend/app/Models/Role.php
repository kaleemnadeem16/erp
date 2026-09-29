<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsActivityWithDefaults;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use LogsActivityWithDefaults;

    protected $fillable = ['team_id', 'name', 'guard_name', 'description'];
}
