<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;

/**
 * Optional second question a subject may answer about *who* may decide one of its requests: the owning module's own record policy (OWN or ALL scope, separation
 * of duties, whether a delegate may stand in). The engine asks it inside the same eligibility every route shares - the inbox, the generic decision route and a
 * module's own Action - so a capability plus an authority ceiling can never reach a record the module would have hidden from that person (non-disclosing: false is
 * simply "not eligible"). A subject that does not implement it keeps the engine's capability-and-ceiling rule unchanged.
 */
interface RecordScopedApprovalSubject extends ApprovalSubject
{
    /** `$delegated` is true when `$person` is the delegator whose authority a delegate is exercising. */
    public function mayDecide(User $person, ApprovalRequest $request, bool $delegated): bool;
}
