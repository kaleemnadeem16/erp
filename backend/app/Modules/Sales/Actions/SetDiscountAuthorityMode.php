<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Company configuration only; never activates a duty or grants a holder. No HTTP caller until the integrated milestone. */
final class SetDiscountAuthorityMode
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, string $mode, string $key, int $expectedGeneration, string $reason): array
    {
        SalesAccess::require(ActiveActor::require($actor), PermissionVocabulary::FINANCIAL_CONFIGURE);

        return app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'mode.change', 'mode' => $mode, 'reason' => $reason], function () use ($actor, $mode, $reason): array {
            if (! in_array($mode, ['OFF', 'SHADOW', 'ENFORCE'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Choose OFF, SHADOW or ENFORCE and give a reason of up to 1000 characters.');
            }
            $id = DB::table('sales_discount_mode_events')->insertGetId(['company_id' => app(CompanyContext::class)->id(), 'mode' => $mode, 'actor_id' => $actor->id, 'reason' => trim($reason)]);

            return ['id' => $id, 'mode' => $mode];
        });
    }
}
