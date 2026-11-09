<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Company;

use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/** Branding read-side hardening (issue #95): the change reason is for people who may manage the brand, and an unreadable logo is a plain 404 like a missing one. */
final class CompanyBrandingHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->app->instance(CompanyDutyManifest::class, new CompanyDutyManifest([CompanyDutyManifest::BRANDING => 'ACTIVE']));
    }

    private function saveLogo($admin): int
    {
        $stream = fopen(__DIR__.'/../../../Fixtures/Branding/gray.png', 'rb');

        return app(SaveCompanyBranding::class)($admin, ['display_name' => 'Brand'], $stream, 'Internal note about the change')['version']->logo_blob_id;
    }

    public function test_the_change_reason_is_visible_to_managers_only(): void
    {
        $admin = PurchasingActor::make([CompanyDutyManifest::BRANDING]);
        $this->saveLogo($admin);

        $this->actingAs($admin)->getJson('/api/v1/company/branding')->assertOk()->assertJsonPath('data.reason', 'Internal note about the change');
        $member = PurchasingActor::make([]);
        $response = $this->actingAs($member)->getJson('/api/v1/company/branding')->assertOk()->assertJsonPath('data.reason', null)->assertJsonPath('data.display_name', 'Brand');
        $this->assertStringNotContainsString('Internal note', (string) $response->getContent());
    }

    public function test_an_unreadable_current_logo_is_a_404_not_a_validation_error(): void
    {
        $admin = PurchasingActor::make([CompanyDutyManifest::BRANDING]);
        $blobId = $this->saveLogo($admin);
        $path = StoredObject::path(FileBlob::query()->findOrFail($blobId)->storage_key);

        $this->actingAs($admin)->get('/api/v1/company/branding/logo')->assertOk();
        Storage::disk('files')->delete($path);
        $this->actingAs($admin)->getJson('/api/v1/company/branding/logo')->assertNotFound();
        Storage::disk('files')->put($path, 'tampered');
        $this->actingAs($admin)->getJson('/api/v1/company/branding/logo')->assertNotFound();
    }
}
