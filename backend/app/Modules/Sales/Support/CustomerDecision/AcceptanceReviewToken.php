<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** @internal Encrypted, actor/company/intent-bound review; no cost or public fingerprint. */
final class AcceptanceReviewToken
{
    public const TTL_SECONDS = 600;

    public function issue(User $actor, string $digest): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'company' => app(CompanyContext::class)->id(), 'actor' => $actor->id, 'intent' => $digest, 'until' => now()->timestamp + self::TTL_SECONDS], JSON_THROW_ON_ERROR));
    }

    public function require(User $actor, string $digest, string $token): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($payload) && ($payload['v'] ?? null) === 1 && ($payload['company'] ?? null) === app(CompanyContext::class)->id()
                && ($payload['actor'] ?? null) === $actor->id && ($payload['intent'] ?? null) === $digest && is_int($payload['until'] ?? null) && $payload['until'] > now()->timestamp) {
                return;
            }
        } catch (DecryptException|\JsonException) {
            // Same safe refusal for malformed, foreign and expired reviews.
        }
        throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::PREVIEW_TOKEN_STALE, 'Review this acceptance again before recording it.', 'preview_token');
    }
}
