<?php

namespace Tests\Support\Sales;

use App\Models\User;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReference;
use App\Modules\Sales\Support\Handover\Release\VerifiedServiceRelease;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Test double for the Jobs port: records what it was given, hands out one reference per release event, and can be told to fail after receiving or to not link. */
final class FakeReleasedServiceReceiver implements ReleasedServiceReceiver
{
    /** @var array<int, ReleasedServiceReference> */
    public array $byEvent = [];

    /** @var list<VerifiedServiceRelease> */
    public array $received = [];

    public bool $failAfterReceiving = false;

    public bool $forgetToLink = false;

    /** When true, links are kept in the committed table `zz_fake_receipts` (company_id, release_event_id, job_id, job_code), so a forked process and another session see them. */
    public bool $durable = false;

    /** Every authority question in order ('assertMayReceive', 'receive', 'referenceFor'), so a test can prove WHEN the receiver was asked relative to any result being returned. */
    /** @var list<string> */
    public array $calls = [];

    /** Refuse every authority question once this many have been asked (null = never): 1 passes the coordinator's early look and refuses at the decision point. */
    public ?int $denyAfterAuthorityCalls = null;

    private int $authorityCalls = 0;

    /** Let the next $allowed authority questions pass, then refuse every later one (1 = pass the coordinator's early look, refuse at the decision point). */
    public function refuseAfter(int $allowed): void
    {
        $this->denyAfterAuthorityCalls = $this->authorityCalls + $allowed;
    }

    private int $next = 1;

    public function assertMayReceive(User $actor): void
    {
        $this->calls[] = 'assertMayReceive';
        if ($this->denyAfterAuthorityCalls !== null && ++$this->authorityCalls > $this->denyAfterAuthorityCalls) {
            throw new AuthorizationException('You are not permitted to receive a released service.');
        }
    }

    public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
    {
        $this->calls[] = 'receive';
        $release->assertCurrent();
        $this->received[] = $release;
        if ($this->durable) {
            $jobId = (int) DB::table('zz_fake_receipts')->insertGetId(['company_id' => $release->companyId, 'release_event_id' => $release->releaseEventId, 'job_code' => 'JOB-PENDING']);
            DB::table('zz_fake_receipts')->where('id', $jobId)->update(['job_id' => $jobId, 'job_code' => 'JOB-'.str_pad((string) $jobId, 4, '0', STR_PAD_LEFT)]);

            return new ReleasedServiceReference($jobId, 'JOB-'.str_pad((string) $jobId, 4, '0', STR_PAD_LEFT));
        }
        $reference = new ReleasedServiceReference($this->next, 'JOB-'.str_pad((string) $this->next++, 4, '0', STR_PAD_LEFT));
        if ($this->failAfterReceiving) {
            throw new RuntimeException('Injected failure after the receiver wrote.');
        }
        if (! $this->forgetToLink) {
            $this->byEvent[$release->releaseEventId] = $reference;
        }

        return $reference;
    }

    public function referenceFor(int $releaseEventId): ?ReleasedServiceReference
    {
        $this->calls[] = 'referenceFor';
        if ($this->durable) {
            $row = DB::table('zz_fake_receipts')->where('release_event_id', $releaseEventId)->first();

            return $row === null ? null : new ReleasedServiceReference((int) $row->job_id, (string) $row->job_code);
        }

        return $this->byEvent[$releaseEventId] ?? null;
    }
}
