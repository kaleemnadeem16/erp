<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

/** The matrix's grant classification (R9, ADR 0138). It says how a duty may be held; it never names a person or a role and grants nothing. */
enum DutyClass: string
{
    /** E — an existing CRM duty: exact contract and holders retained, no new assignment. */
    case Existing = 'E';

    /** E-S — an existing sensitive duty (ADR 0114): excluded from automatic grants. */
    case ExistingSensitive = 'E-S';

    /** N — a new ordinary duty: a named holder recipe must be explicitly approved at rollout; no CRM or editor continuity is inferred. */
    case NewOrdinary = 'N';

    /** S — a sensitive duty: an explicit authorised holder or a qualifying approved full-access mandate with scope proof. */
    case Sensitive = 'S';

    /** D-S — a dormant, reserved sensitive design: never registered or enabled until its own contract is approved and delivered. */
    case DormantSensitive = 'D-S';

    public function isExisting(): bool
    {
        return $this === self::Existing || $this === self::ExistingSensitive;
    }
}
