<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Database\Seeders\Demo\ReceiptPackageEvidenceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptPackageEvidenceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_posted_demo_covers_mixed_alternative_and_held_arrivals_and_preserves_reruns(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        foreach (['sealed-and-loose', 'alternative-form', 'qa-held-sealed'] as $key) {
            $receipt = GoodsReceipt::query()->where('idempotency_key', 'demo-physical-receipt-'.$key)->sole();
            $line = $receipt->lines()->sole();
            $this->assertSame('2.0000', $line->received_package_qty);
            $this->assertSame('4.0000', $line->stock_qty);
            $this->assertSame('3400.0000', $line->provisional_amount);
            $operation = PackageOperation::query()->where('source_receipt_line_id', $line->id)->sole();
            $states = PackageState::query()->where('operation_id', $operation->id)->orderBy('id')->get();
            $this->assertSame('RECEIPT', $operation->evidence_kind);
            if ($key === 'sealed-and-loose') {
                $this->assertSame(['SEALED', 'OPEN_LOOSE'], $states->pluck('form')->all());
            } elseif ($key === 'alternative-form') {
                $this->assertSame('can-2l', $states->sole()->packaging_code);
                $this->assertSame('Agreed 2 L box', $line->package_name);
            } else {
                $this->assertSame('HELD', $states->sole()->availability);
            }
        }
        $before = PackageOperation::query()->where('evidence_kind', 'RECEIPT')->get()->toArray();
        $this->seed(ReceiptPackageEvidenceDemoSeeder::class);
        $this->assertSame($before, PackageOperation::query()->where('evidence_kind', 'RECEIPT')->get()->toArray());
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(ReceiptPackageEvidenceDemoSeeder::class)->run();
    }
}
