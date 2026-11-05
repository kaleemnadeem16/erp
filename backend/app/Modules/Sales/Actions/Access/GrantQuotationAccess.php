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

/** Named record eligibility, never a capability or ownership grant. */
final class GrantQuotationAccess
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $familyId, int $recipientId, string $level, int $expectedOwnership, int $expectedAccess, ?string $requestKey, string $reason, ?string $startsAt = null, ?string $endsAt = null): QuotationShareOutcome
    {
        QuotationSharing::requireAuthority($actor, Duty::QUOTATION_ACCESS_DELEGATE, $level);
        if (! in_array($level, ['VIEW', 'PREPARE'], true)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A share level is VIEW or PREPARE.', 'level');
        }
        $reason = QuotationOwnership::reason($reason);
        $startsAt = QuotationSharing::instant($startsAt, 'starts_at');
        $endsAt = QuotationSharing::instant($endsAt, 'ends_at');
        $outcome = $this->operations->run(SalesActorContext::forUser($actor, 'quotation.share.grant'), 'quotation.share.grant', $requestKey,
            ['family_id' => $familyId, 'recipient_id' => $recipientId, 'level' => $level, 'expected_ownership_generation' => $expectedOwnership, 'expected_access_generation' => $expectedAccess, 'reason' => $reason, 'starts_at' => $startsAt, 'ends_at' => $endsAt],
            fn (): QuotationFamily => QuotationSharing::lockFamily($actor, $familyId, Duty::QUOTATION_ACCESS_DELEGATE, $level),
            function (QuotationFamily $family) use ($actor, $recipientId, $level, $expectedOwnership, $expectedAccess, $reason, $startsAt, $endsAt): array {
                QuotationOwnership::requireResolved($family);
                QuotationOwnership::requireGenerations($family, $expectedOwnership, $expectedAccess);
                $recipient = QuotationSharing::recipient($recipientId, $actor, $family, $level);
                $start = $startsAt ?? DB::selectOne('SELECT clock_timestamp() AS instant')->instant;
                if ($endsAt !== null && new \DateTimeImmutable($endsAt) <= new \DateTimeImmutable($start)) {
                    throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'The end must be after the start.', 'ends_at');
                }
                if ($recipient['already_eligible']) {
                    return QuotationShareOutcome::result($family->id, null, $family->ownership_generation, $family->access_generation, false);
                }
                // Match the storage overlap rule, including future intervals and permanent epoch invalidation.
                $epochs = DB::table('company_access_generations')->where('company_id', $family->company_id)->first();
                $overlap = DB::table('quotation_access_grants as g')
                    ->join('users as issuer', 'issuer.id', '=', 'g.issuer_id')->join('users as recipient', 'recipient.id', '=', 'g.recipient_id')
                    ->where('g.company_id', $family->company_id)->where('g.family_id', $family->id)->where('g.recipient_id', $recipientId)->where('g.level', $level)
                    ->where('g.ownership_generation', $family->ownership_generation)->where('g.organisation_generation', $epochs->organisation_generation)->where('g.capability_generation', $epochs->capability_generation)
                    ->whereColumn('g.issuer_authorization_generation', 'issuer.authorization_generation')->whereColumn('g.recipient_authorization_generation', 'recipient.authorization_generation')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('quotation_access_revocations as r')->whereColumn('r.company_id', 'g.company_id')->whereColumn('r.grant_id', 'g.id'))
                    ->whereRaw("tstzrange(g.starts_at,g.ends_at,'[)') && tstzrange(?::timestamptz,?::timestamptz,'[)')", [$start, $endsAt])->exists();
                if ($overlap) {
                    throw QuotationRefusal::make('QUOTATION_SHARE_OVERLAP', 'An effective share of this level already overlaps the requested interval.', 'recipient_id');
                }
                $grant = DB::table('quotation_access_grants')->insertGetId([
                    'company_id' => $family->company_id, 'family_id' => $family->id, 'issuer_id' => $actor->id, 'recipient_id' => $recipientId, 'actor_id' => $actor->id,
                    'level' => $level, 'starts_at' => $start, 'ends_at' => $endsAt, 'reason' => $reason,
                    'issuer_authorization_generation' => DB::table('users')->where('company_id', $family->company_id)->where('id', $actor->id)->value('authorization_generation'),
                    'recipient_authorization_generation' => $recipient['user']->authorization_generation,
                    'organisation_generation' => $epochs->organisation_generation, 'capability_generation' => $epochs->capability_generation, 'ownership_generation' => $family->ownership_generation,
                ]);
                $family->refresh();

                return QuotationShareOutcome::result($family->id, $grant, $family->ownership_generation, $family->access_generation, true);
            });

        return QuotationShareOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
