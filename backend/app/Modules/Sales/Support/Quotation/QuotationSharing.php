<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Support\Scope\CompanyAccessFence;
use App\Support\Scope\RecordScope;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** All management uses independent non-delegated view/operation scopes, under the security fence. */
final class QuotationSharing
{
    /** @return list<string> */
    public static function duties(string $managementDuty, string $level = 'VIEW'): array
    {
        return array_values(array_unique([$managementDuty, Duty::QUOTATION_VIEW, ...($level === 'PREPARE' ? [Duty::QUOTATION_PREPARE] : [])]));
    }

    public static function requireAuthority(User $actor, string $duty, string $level = 'VIEW'): void
    {
        DB::transaction(function () use ($actor, $duty, $level): void {
            // A caller may already own an outer transaction. Start exclusive so this preliminary
            // check cannot retain a shared fence and later upgrade it inside QuotationOperation.
            app(CompanyAccessFence::class)->lockAll();
            app(QuotationAccess::class)->queryFor($actor, self::duties($duty, $level), false);
        });
    }

    public static function lockFamily(User $actor, int $familyId, string $duty, string $level = 'VIEW'): QuotationFamily
    {
        return app(QuotationAccess::class)->requireFamily($actor, $familyId, self::duties($duty, $level), true, false);
    }

    /** @return array{user:User,already_eligible:bool} */
    public static function recipient(int $recipientId, User $issuer, QuotationFamily $family, string $level): array
    {
        $recipient = User::query()->whereKey($recipientId)->first();
        if ($recipient === null || $recipient->id === $issuer->id || ($recipient = ActiveActor::current($recipient)) === null) {
            throw self::recipientRefusal();
        }
        $already = true;
        foreach ($level === 'PREPARE' ? [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] : [Duty::QUOTATION_VIEW] as $duty) {
            try {
                $eligible = app(QuotationAccess::class)->queryFor($recipient, [$duty], false)->whereKey($family->id)->exists();
            } catch (AuthorizationException) {
                throw self::recipientRefusal();
            }
            $scope = app(RecordScope::class)->resolveForResource($recipient, $duty, QuotationFamilyScopeAdapter::RESOURCE);
            if (! $eligible && ! $scope->allowsNamedDelegation()) {
                throw self::recipientRefusal();
            }
            $already = $already && $eligible;
        }

        return ['user' => $recipient, 'already_eligible' => $already];
    }

    public static function instant(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use an ISO 8601 instant with an explicit time zone.', $field);
        }
        try {
            $instant = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException;
            }
        } catch (\Exception) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use a valid ISO 8601 instant.', $field);
        }

        $instant = $instant->setTimezone(new \DateTimeZone('UTC'));
        if (! preg_match('/^(?!0000)\d{4}$/D', $instant->format('Y'))) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use a supported ISO 8601 instant.', $field);
        }

        return $instant->format('Y-m-d H:i:s.uP');
    }

    private static function recipientRefusal(): DomainInvariantException
    {
        return QuotationRefusal::make(QuotationRefusal::SHARE_RECIPIENT_INELIGIBLE, 'That recipient is not eligible for this quotation access.', 'recipient_id');
    }
}
