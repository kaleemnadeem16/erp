<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_has_a_published_control_set_and_a_prepared_unissued_draft_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(1, QuotationControlSet::query()->count());
        $this->assertSame(['FLOOR', 'FLOOR'], QuotationControlSet::query()->sole()->controls->pluck('kind')->all());
        $revision = QuotationRevision::query()->sole();
        $this->assertSame('DRAFT', $revision->state);
        $this->assertSame(['SERVICE_ITEM', 'DIRECT_ITEM', 'MANUAL'], QuotationDraftLine::query()->orderBy('id')->pluck('source_kind')->all());
        $this->assertSame(0, QuotationIssue::query()->count(), 'issue stays blocked until the Tax jurisdiction/classification source exists');
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->assertTrue($admin->hasPermissionTo(Sales::QUOTATION_ISSUE));
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_ISSUE), 'the staged duties are switched on only while seeding');
    }
}
