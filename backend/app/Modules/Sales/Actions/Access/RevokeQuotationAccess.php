<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions\Access;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationOwnership;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\Quotation\QuotationShareOutcome;
use App\Modules\Sales\Support\Quotation\QuotationSharing;
use Illuminate\Support\Facades\DB;

/** An expired grant may still receive its permanent append-only revocation. */
final class RevokeQuotationAccess
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $familyId, int $grantId, int $expectedOwnership, int $expectedAccess, ?string $requestKey, string $reason): QuotationShareOutcome
    {
        $this->assertMayManage($actor);
        $reason = QuotationOwnership::reason($reason);
        $outcome = $this->operations->run(SalesActorContext::forUser($actor, 'quotation.share.revoke'), 'quotation.share.revoke', $requestKey,
            ['family_id' => $familyId, 'grant_id' => $grantId, 'expected_ownership_generation' => $expectedOwnership, 'expected_access_generation' => $expectedAccess, 'reason' => $reason],
            fn (): QuotationFamily => QuotationSharing::lockFamily($actor, $familyId, Duty::QUOTATION_ACCESS_REVOKE),
            function (QuotationFamily $family) use ($actor, $grantId, $expectedOwnership, $expectedAccess, $reason): array {
                QuotationOwnership::requireResolved($family);
                QuotationOwnership::requireGenerations($family, $expectedOwnership, $expectedAccess);
                if (! DB::table('quotation_access_grants')->where('company_id', $family->company_id)->where('family_id', $family->id)->where('id', $grantId)->exists()) {
                    throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'grant_id');
                }
                $changed = ! DB::table('quotation_access_revocations')->where('company_id', $family->company_id)->where('grant_id', $grantId)->exists();
                if ($changed) {
                    DB::table('quotation_access_revocations')->insert(['company_id' => $family->company_id, 'family_id' => $family->id, 'grant_id' => $grantId, 'revoked_by' => $actor->id, 'reason' => $reason]);
                    $family->refresh();
                }

                return QuotationShareOutcome::result($family->id, $grantId, $family->ownership_generation, $family->access_generation, $changed);
            });

        return QuotationShareOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    private function assertMayManage(User $actor): void
    {
        QuotationSharing::requireAuthority($actor, Duty::QUOTATION_ACCESS_REVOKE);
    }
}
