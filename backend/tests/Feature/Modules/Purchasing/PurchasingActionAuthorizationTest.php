<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Actions\ShortClosePurchaseOrderLine;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;

class PurchasingActionAuthorizationTest extends PurchaseOrderFoundationTest
{
    public function test_issue_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $this->assertDenied(fn () => app(IssuePurchaseOrder::class)(new PurchaseOrder, User::factory()->create()));
    }

    public function test_cancel_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $this->assertDenied(fn () => app(CancelPurchaseOrder::class)(new PurchaseOrder, 'Denied', User::factory()->create()));
    }

    public function test_short_close_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $this->assertDenied(fn () => app(ShortClosePurchaseOrderLine::class)(new PurchaseOrderLine, User::factory()->create()));
    }

    public function test_post_receipt_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $this->assertDenied(fn () => app(PostGoodsReceipt::class)(new GoodsReceipt, User::factory()->create()));
    }

    public function test_reverse_receipt_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $actor = User::factory()->create();
        $this->assertDenied(fn () => app(ReverseGoodsReceipt::class)(new GoodsReceipt, 'Denied', $actor->id));
    }

    public function test_qa_action_rejects_an_unprivileged_non_http_caller(): void
    {
        $actor = User::factory()->create();
        $this->assertDenied(fn () => app(PostQualityDisposition::class)(
            new GoodsReceiptLine,
            ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'Denied'],
            $actor->id,
        ));
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
        } catch (DomainInvariantException $exception) {
            $this->assertStringContainsString('not permitted', $exception->getMessage());

            return;
        }

        $this->fail('Expected the action to reject an unprivileged caller.');
    }
}
