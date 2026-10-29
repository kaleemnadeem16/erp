<?php

namespace Tests\Unit\Modules\Jobs;

use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyCatalogue;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * J-AU0 — the Jobs duty catalogue and manifest as pure data: the 23 names of Task F exactly, every one SPECIFIED, none STAGED or ACTIVE, nothing automatically
 * grantable, and a manifest that fails closed for any name it does not list as ACTIVE (docs/phases/phase-5-task-f-jobs-access-plan.md; JF01).
 */
class JobDutyManifestTest extends TestCase
{
    private const NAMES = [
        'job.view', 'job.receive', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.completion.withdraw', 'job.cancel_unused', 'job.stop', 'job.close',
        'job.history.record', 'job.history.review', 'job.history.correct', 'job.labour.record', 'job.labour.amount.declare', 'job.labour.recognise', 'job.labour.rate.adopt',
        'job.labour.correct', 'job.cost.view', 'job.cost.coverage.declare', 'job.cost.coverage.reconcile', 'job.cost.export',
    ];

    private function duty(string $name, JobDutyStatus $status = JobDutyStatus::Specified): JobDuty
    {
        return new JobDuty($name, $status, 'op', 'projection', 'gate');
    }

    public function test_the_catalogue_is_exactly_the_23_proposed_names_in_order_and_all_are_specified(): void
    {
        $manifest = JobDutyManifest::shipped();
        $names = array_map(fn (JobDuty $duty): string => $duty->name, $manifest->all());

        $this->assertSame(self::NAMES, $names);
        $this->assertCount(23, $names);
        $this->assertCount(23, $manifest->withStatus(JobDutyStatus::Specified));
        $this->assertSame([], $manifest->withStatus(JobDutyStatus::Staged), 'no guarded Jobs consumer exists, so nothing is staged');
        $this->assertSame([], $manifest->withStatus(JobDutyStatus::Active));
        $this->assertSame([], $manifest->activeNames());
        foreach ($manifest->all() as $duty) {
            $this->assertFalse($duty->isActive(), $duty->name);
            $this->assertFalse($duty->automaticallyGrantable(), $duty->name);
            $this->assertNotSame('', trim($duty->operation).trim($duty->projection).trim($duty->deliveryGate), $duty->name);
            $this->assertNotNull($manifest->find($duty->name));
            $this->assertFalse($manifest->isActive($duty->name));
        }
    }

    public function test_the_exact_spelling_of_cancel_unused_is_kept_and_no_alias_exists(): void
    {
        $manifest = JobDutyManifest::shipped();
        $this->assertNotNull($manifest->find('job.cancel_unused'));
        foreach (['job.cancel-unused', 'job.cancel.unused', 'job.cancel', 'job.cancelunused', 'job.cancel_unused '] as $alias) {
            $this->assertNull($manifest->find($alias), $alias);
        }
        // Each duty is its own: there is no combined, wildcard or catch-all name.
        foreach (['job.*', 'job', 'jobs.view', 'job.manage', 'job.approve', 'job.assign_and_start', 'job.admin', 'job.claim.release'] as $name) {
            $this->assertNull($manifest->find($name), $name);
        }
    }

    public function test_names_are_validated_exactly(): void
    {
        foreach (['job', 'job.', 'job.*', 'job.view.*', 'jobs.view', 'sales.quotation.view', 'documents.template.manage', 'Job.view', 'job.View', 'job..view', 'job.view ', ' job.view', 'job.1x', 'job.cancel-unused', ''] as $bad) {
            try {
                $this->duty($bad);
                $this->fail("[{$bad}] must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['job.view', 'job.cancel_unused', 'job.labour.amount.declare'] as $good) {
            $this->assertSame($good, $this->duty($good)->name);
        }
        foreach ([['', 'p', 'g'], ['o', ' ', 'g'], ['o', 'p', '']] as [$operation, $projection, $gate]) {
            try {
                new JobDuty('job.view', JobDutyStatus::Specified, $operation, $projection, $gate);
                $this->fail('A duty needs an operation, a projection and a gate.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_duplicate_name_refuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JobDutyManifest([$this->duty('job.view'), $this->duty('job.start'), $this->duty('job.view', JobDutyStatus::Staged)]);
    }

    public function test_only_an_active_listing_is_active_and_unknown_names_fail_closed(): void
    {
        $manifest = new JobDutyManifest([
            $this->duty('job.view', JobDutyStatus::Active), $this->duty('job.assign', JobDutyStatus::Staged), $this->duty('job.start'),
        ]);

        $this->assertTrue($manifest->isActive('job.view'));
        $this->assertFalse($manifest->isActive('job.assign'), 'staged is not live');
        $this->assertFalse($manifest->isActive('job.start'), 'specified is not live');
        $this->assertFalse($manifest->isActive('job.close'), 'unlisted is not live');
        $this->assertFalse($manifest->isActive('job.*'));
        $this->assertFalse($manifest->isActive(''));
        $this->assertSame(['job.view'], $manifest->activeNames());
        $this->assertCount(1, $manifest->withStatus(JobDutyStatus::Staged));
        // Even an ACTIVE duty is never automatically grantable.
        $this->assertFalse($manifest->find('job.view')->automaticallyGrantable());
    }

    public function test_the_manifest_has_no_registration_path(): void
    {
        $this->assertFalse(method_exists(JobDutyManifest::class, 'registerWith'), 'registering Jobs needs the shared strict no-growth contract, which does not exist yet');
        $this->assertSame(23, count(JobDutyCatalogue::all()));
    }
}
