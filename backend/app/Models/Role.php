<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsActivityWithDefaults;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 */
class Role extends SpatieRole
{
    use LogsActivityWithDefaults;

    protected $fillable = ['team_id', 'name', 'guard_name', 'description'];
}
