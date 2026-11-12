<?php

namespace Tests\Support\Sales;

use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReference;
use App\Modules\Sales\Support\Handover\Release\VerifiedServiceRelease;
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

    private int $next = 1;

    public function receive(VerifiedServiceRelease $release): ReleasedServiceReference
    {
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
        if ($this->durable) {
            $row = DB::table('zz_fake_receipts')->where('release_event_id', $releaseEventId)->first();

            return $row === null ? null : new ReleasedServiceReference((int) $row->job_id, (string) $row->job_code);
        }

        return $this->byEvent[$releaseEventId] ?? null;
    }
}
