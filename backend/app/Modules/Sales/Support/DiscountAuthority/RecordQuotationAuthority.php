<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;

/** @internal Called only by guarded quotation operations under the common fences. */
final class RecordQuotationAuthority
{
    public function record(User $actor, QuotationRevision $revision): ?int
    {
        $mode = app(DiscountConfiguration::class)->mode();
        if ($mode === 'OFF') {
            return null;
        }
        $fresh = ActiveActor::require($actor, 'record quotation authority');

        return app(AuthorityEvaluationStore::class)->record($fresh, $revision, $mode, app(AssessDiscountAuthority::class)->assess($revision));
    }
}
