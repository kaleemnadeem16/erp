<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use Database\Seeders\Demo\QuotationDocumentsDemoSeeder;
use Database\Seeders\Demo\Sales\QuotationIssuingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** The documents demo is local-preview only, uses the named demo login, goes through the real Actions, says why it skipped and a rerun adds nothing. */
final class QuotationDocumentsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function localPreview(): void
    {
        config(['sales.dev_preview.quotation_duties' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->app->forgetInstance(SalesDutyManifest::class);
    }

    private function seedDocuments(): string
    {
        Artisan::call('db:seed', ['--class' => QuotationDocumentsDemoSeeder::class]);

        return Artisan::output();
    }

    public function test_it_writes_nothing_outside_the_local_preview(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(0, QuotationRevision::query()->count());
        $this->assertStringContainsString('skipped', $this->seedDocuments());
    }

    public function test_it_says_which_duties_the_demo_login_lacks_instead_of_failing_obscurely(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->localPreview();
        $this->seed(QuotationIssuingDemoSeeder::class);

        $output = $this->seedDocuments();

        $this->assertStringContainsString('does not hold', $output);
        $this->assertStringContainsString('sales:dev-grant-quotation', $output);
        $this->assertStringNotContainsString('not permitted', $output);
    }

    public function test_the_local_preview_builds_the_documents_and_deliveries_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->localPreview();
        $this->seed(QuotationIssuingDemoSeeder::class);
        foreach (SalesDevPreview::all() as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->artisan('sales:dev-grant-quotation')->assertSuccessful();
        $before = QuotationRevision::query()->count();

        $this->assertStringContainsString('Quotation documents demo: one issued quotation', $this->seedDocuments());
        $after = QuotationRevision::query()->count();
        $deliveries = QuotationDocumentDelivery::query()->count();
        $this->assertSame($before + 1, $after);
        $this->assertGreaterThanOrEqual(5, $deliveries, 'original, wrong+correction, never-sent+withdrawal');

        $this->seedDocuments();
        $this->assertSame([$after, $deliveries], [QuotationRevision::query()->count(), QuotationDocumentDelivery::query()->count()]);
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->assertFalse($admin->getAllPermissions()->pluck('name')->contains('sales.document.repair'));

        // Branding: made once through the real Action, the STAGED duty is neither left active nor left granted.
        $this->assertSame(1, CompanyBrandVersion::query()->count());
        $brand = CompanyBrandVersion::query()->firstOrFail();
        $this->assertSame(['XYZ Paints', 'image/png'], [$brand->display_name, $brand->logo_media_type]);
        $this->assertFalse(app(CompanyDutyManifest::class)->isActive(CompanyDutyManifest::BRANDING));
        $this->assertFalse($admin->getDirectPermissions()->pluck('name')->contains(CompanyDutyManifest::BRANDING));
        $this->assertSame('PINNED', DocumentManifest::query()->orderByDesc('id')->firstOrFail()->brand_state, 'the demo PDF is branded');
    }
}
