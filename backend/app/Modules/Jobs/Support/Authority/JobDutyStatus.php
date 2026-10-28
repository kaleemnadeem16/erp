<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Authority;

/**
 * Where a Jobs duty is in its life. A name is a plan, a staged consumer and a live permission are three different things, and only the last one grants anything
 * (docs/phases/phase-5-task-f-jobs-access-plan.md).
 */
enum JobDutyStatus: string
{
    /** Named, with an operation and a minimum projection, but no guarded Jobs consumer exists: not registered, not grantable, refused by `JobAccess`. */
    case Specified = 'SPECIFIED';

    /**
     * A real consumer (Request where HTTP applies, owning Action, safe projection and negative tests) is delivered, yet the duty is still not registered and
     * `JobAccess` refuses every caller — even a hand-made permission row or a mandate. "Planned" is never STAGED.
     */
    case Staged = 'STAGED';

    /** The same reviewed implementation plus every source, holder, registration, no-automatic-growth and staging gate, and a separately authorised release. */
    case Active = 'ACTIVE';
}
