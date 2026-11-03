<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Database\Seeders\Demo\Sales\QuotationConcessionsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotationConcessionsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** What the local preview does: view and prepare usable. The discount and override duties stay staged and are switched on only inside the seeder. */
    private function activatePreview(): void
    {
        $on = [Sales::QUOTATION_PREPARE, Sales::QUOTATION_VIEW];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $on, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
    }

    public function test_the_concession_scenarios_are_built_through_the_actions_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame(0, QuotationRevision::query()->count(), 'nothing is built while the quotation duties are staged');
        $this->activatePreview();
        $this->seed(QuotationConcessionsDemoSeeder::class);
        $first = [QuotationRevision::query()->count(), QuotationDraftLine::query()->count(), DB::table('sales_quotation_operation_receipts')->count()];
        $this->seed(QuotationConcessionsDemoSeeder::class);
        $this->assertSame($first, [QuotationRevision::query()->count(), QuotationDraftLine::query()->count(), DB::table('sales_quotation_operation_receipts')->count()]);

        $lines = QuotationDraftLine::query()->whereNotNull('discount_kind')->orWhereNotNull('net_override')->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $this->assertSame([null, 'DISCOUNT_ABOVE_LIMIT', 'PRICE_OVERRIDE'], $lines->pluck('approval_reason')->all(), 'a discount at the limit needs no approval; above it and an override do');
        $this->assertSame(['PERCENT', 'PERCENT', null], $lines->pluck('discount_kind')->all());
        $this->assertNotNull($lines[0]->discount_entered_by);
        $this->assertNotNull($lines[2]->override_reason);
        $document = DB::table('quotation_draft_document_discounts')->sole();
        $this->assertSame(['PERCENT', false], [$document->kind, (bool) $document->approval_required], 'a 5% document discount is within the 10% routine limit');
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_DISCOUNT), 'the duties are switched on only while seeding');
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_PRICE_OVERRIDE));
    }
}
