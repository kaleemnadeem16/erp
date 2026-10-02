<?php

namespace Tests\Feature\Modules\Numbering;

use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingValue;
use App\Modules\Purchasing\Numbering\GoodsReceiptNumbering;
use App\Modules\Purchasing\Numbering\PurchaseOrderNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The data step of the numbering migration: purchase orders and goods receipts keep exactly the numbering they had — same
 * prefix, same padding, same next number, still gapless — so deploying the framework changes no visible number.
 */
class NumberingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runDataStep(): void
    {
        $files = glob(database_path('migrations/*_create_numbering_framework_tables.php'));
        $migration = require $files[0];
        $method = new \ReflectionMethod($migration, 'migrateExistingSequences');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    private function sequence(string $docType, ?string $prefix, int $next, int $padding): void
    {
        DB::table('sequences')->insert(['company_id' => 1, 'doc_type' => $docType, 'prefix' => $prefix, 'next_number' => $next, 'padding' => $padding, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_existing_purchase_order_and_receipt_numbering_continues_unchanged(): void
    {
        $this->sequence('purchase_order', 'PO-', 42, 5);
        $this->sequence('goods_receipt', null, 7, 4);
        $this->sequence('invoice', 'INV-', 1, 4); // not a registered numbered document: left to the old mechanism

        $this->runDataStep();

        $series = NumberingSeries::query()->where('doc_type', 'PURCHASE_ORDER')->firstOrFail();
        $version = NumberingSeriesVersion::query()->where('series_id', $series->id)->firstOrFail();
        $this->assertTrue($series->is_default);
        $this->assertSame('PO-{SEQ:5}', $version->template);
        $this->assertSame('NONE', $version->scope_period);
        $this->assertSame('GAPLESS', $version->continuity->value, 'what those numbers were produced under');
        $counter = NumberingCounter::query()->where('series_id', $series->id)->firstOrFail();
        $this->assertSame('LOCKED', $counter->allocator);
        $this->assertSame(42, $counter->next_number);
        $this->assertSame(2, NumberingSeries::query()->count(), 'the unregistered doc type was not migrated');

        $engine = app(NumberingService::class);
        $now = CarbonImmutable::parse('2026-10-02 10:00:00');
        $order = fn () => $engine->allocate(new NumberingContext(PurchaseOrderNumbering::KEY, $now))->number;
        $receipt = fn () => $engine->allocate(new NumberingContext(GoodsReceiptNumbering::KEY, $now, ['WAREHOUSE' => new NumberingValue('LHR')]))->number;

        $this->assertSame('PO-00042', $order(), 'the very next order number is exactly the one the old sequence would have given');
        $this->assertSame('PO-00043', $order());
        $this->assertSame('0007', $receipt());
        $this->assertSame('0008', $receipt());
    }

    public function test_a_prefix_the_framework_cannot_represent_stops_the_migration_rather_than_changing_numbers(): void
    {
        $this->sequence('purchase_order', 'PO #', 1, 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains characters document numbering does not allow');
        $this->runDataStep();
    }

    public function test_the_default_for_a_fresh_installation_reproduces_todays_visible_numbering(): void
    {
        $engine = app(NumberingService::class);

        $this->assertSame('0001', $engine->allocate(new NumberingContext(PurchaseOrderNumbering::KEY, CarbonImmutable::now()))->number);
        $this->assertSame('0002', $engine->allocate(new NumberingContext(PurchaseOrderNumbering::KEY, CarbonImmutable::now()))->number);
        $this->assertSame('ALLOW_GAPS', NumberingSeriesVersion::query()->firstOrFail()->continuity->value, 'the registered default for an operational document');
    }
}
