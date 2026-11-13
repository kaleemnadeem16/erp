<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostRejectedStockExit;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Support\JobSource\InventoryJobSourceApplicability;
use App\Support\JobSource\FamilyAssessment;
use Tests\Support\BuildsRejectedStockWorld;

class InventoryRejectedJobSourceProvenanceTest extends RecordPackageCheckpointTest
{
    use BuildsRejectedStockWorld;

    public function test_job_named_exit_document_and_package_issue_are_provenance_only(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $input = $this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [
            ['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1],
        ], extra: ['document_type' => 'SERVICE_JOB', 'document_id' => 987654]);
        $post = app(PostRejectedStockExit::class);
        $exit = $post($input, $this->world['exiter']);
        $this->assertSame('SERVICE_JOB', $exit->document_type);
        $this->assertSame($exit->id, $post($input, $this->world['exiter'])->id);
        $this->flush();
        $parent = PackageOperation::query()->where('location_id', $this->world['a'])->latest('id')->firstOrFail();
        $issueInput = ['predecessor_id' => $parent->id, 'request_key' => 'job-named-provenance', 'reason' => 'Physical issue',
            'source_type' => 'JOB_MATERIAL', 'source_id' => 987654, 'moved_at' => now(),
            'draw' => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]];
        $issue = app(IssuePackagedStock::class)($issueInput, $this->world['actor']);
        $this->assertSame('JOB_MATERIAL', $issue['move']->source_type);
        $this->assertSame($issue['move']->id, app(IssuePackagedStock::class)($issueInput, $this->world['actor'])['move']->id);
        $this->flush();
        $answer = (new InventoryJobSourceApplicability)->assess(1);
        $this->assertSame(FamilyAssessment::NOT_APPLICABLE, $answer->status);
        $this->assertSame([], $answer->supportedBindings, 'Neither document metadata nor a stock provenance string validates a Job allocation.');
    }
}
