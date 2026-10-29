<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The canonical, versioned intent of one lifecycle command (contract v1). The SEMANTIC hash names the business effect — operation, contract version, Job, target
 * ids, intended inputs, reason, the claimed occurrence and the declared expected versions — and excludes the transport key, the actor and server time, so the same
 * business command is recognised through a fresh key or another actor. The REQUEST hash adds the actor and key, so a used key with a different request conflicts.
 * Canonical form: UTF-8 JSON, object keys sorted, explicit nulls, integer ids, lowercase UUIDs, UTC ISO-8601 times; changing it needs a new contract version.
 */
final readonly class JobLifecycleIntent
{
    public const CONTRACT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $targets  the operation's own inputs (assignee, interval, instructions, hold id, ...)
     * @param  array<string, mixed>  $expected  declared expected versions: generation (required), baseline_version, assignment_sequence, open_holds, source_vector (null or absent = not asserted)
     */
    public function __construct(
        public string $operation,
        public int $jobId,
        public string $commandIdentity,
        public ?string $reason,
        public array $targets,
        public array $expected,
        public ?Carbon $occurredAt = null,
        public string $occurrencePrecision = 'NONE',
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $commandIdentity) !== 1) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'A command identity must be a lowercase UUID.', 'command_identity');
        }
        if (! is_int($expected['generation'] ?? null) || $expected['generation'] < 1) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'The expected Job generation is required.', 'expected_generation');
        }
        if ($reason !== null && (trim($reason) === '' || mb_strlen($reason) > 1000)) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'A reason is 1 to 1000 characters.', 'reason');
        }
        if (trim($occurrencePrecision) === '' || mb_strlen($occurrencePrecision) > 20) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'The occurrence precision is required.', 'occurrence_precision');
        }
    }

    public static function newCommandIdentity(): string
    {
        return strtolower((string) Str::uuid());
    }

    /** @return array<string, mixed> */
    public function semantic(): array
    {
        $expected = $this->expected;
        ksort($expected);
        $targets = $this->targets;
        ksort($targets);

        return [
            'contract_version' => self::CONTRACT_VERSION, 'operation' => $this->operation, 'job_id' => $this->jobId, 'command_identity' => $this->commandIdentity,
            'reason' => $this->reason === null ? null : trim($this->reason), 'targets' => $targets, 'expected' => $expected,
            'occurred_at' => $this->occurredAt?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'occurrence_precision' => $this->occurrencePrecision,
        ];
    }

    public function semanticHash(): string
    {
        return hash('sha256', json_encode($this->semantic(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function requestHash(int $actorId, string $requestKey): string
    {
        return hash('sha256', json_encode(['semantic' => $this->semanticHash(), 'actor_id' => $actorId, 'request_key' => $requestKey], JSON_THROW_ON_ERROR));
    }
}
