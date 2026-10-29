<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Illuminate\Auth\Access\AuthorizationException;

/** The caller is not entitled to cost evidence for this workflow. A fixed message, so a refusal says nothing about what was being costed. */
final class CostEvidenceNotAuthorised extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('You are not permitted to use cost evidence for that workflow.');
    }
}
