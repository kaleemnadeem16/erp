<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * "Only my units" on one assignment (ADR 0089): a role given to a person, or a direct permission. Having no row means the assignment is
 * company-wide, as every assignment was before scope existed.
 *
 * @property int $user_id
 * @property int|null $role_id
 * @property string|null $permission
 * @property string $scope
 */
class AccessScopeLimit extends Model
{
    use BelongsToCompany;

    public const MY_UNITS = 'MY_UNITS';

    public const OWN = 'OWN';

    public const OWN_OR_DELEGATED = 'OWN_OR_DELEGATED';

    /** Records the person is named on (a purchase order's buyer). Its own scope: it is not OWN, and it never confers a capability. */
    public const ASSIGNED = 'ASSIGNED';

    protected $guarded = ['id'];
}
