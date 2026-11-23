<?php

namespace Tests\Unit\Modules\Jobs;

use App\Modules\Jobs\Http\Resources\JobCloseoutAssessmentResource;
use App\Modules\Jobs\Http\Resources\JobCommandResource;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobCommandResourceTest extends TestCase
{
    public function test_resources_serialize_only_safe_original_outcome_fields_without_queries(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $command = new JobCommandResource(new JobLifecycleOutcome(7, 31, 5, 'COMPLETE', 'COMPLETED', 5, true));
        $this->assertSame(['job_id' => 7, 'event_id' => 31, 'sequence' => 5, 'operation' => 'COMPLETE', 'state' => 'COMPLETED', 'generation' => 5, 'replayed' => true], $command->resolve(new Request));
        $assessment = new JobCloseoutAssessmentResource(new JobCloseoutOutcome(7, 9, 'BLOCKED', ['FULFILMENT_MISSING'], 5, false));
        $this->assertSame(['job_id' => 7, 'assessment_id' => 9, 'status' => 'BLOCKED', 'blockers' => ['FULFILMENT_MISSING'], 'generation' => 5, 'replayed' => false], $assessment->resolve(new Request));
        $this->assertSame([], $queries);
    }
}
