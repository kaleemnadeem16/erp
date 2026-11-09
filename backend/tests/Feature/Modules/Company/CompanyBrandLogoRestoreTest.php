<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Company;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Company\Actions\ReadCompanyBrand;
use App\Modules\Company\Actions\ReadCompanyBrandLogo;
use App\Modules\Company\Actions\RestoreCompanyBrandLogo;
use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\StoredObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/** ADR 0175: a pinned logo that went missing comes back only as the exact original bytes, under the same blob; a new upload is never the recovery. */
final class CompanyBrandLogoRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->app->instance(CompanyDutyManifest::class, new CompanyDutyManifest([CompanyDutyManifest::BRANDING => 'ACTIVE']));
    }

    private function stream(string $bytes)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Branding/'.$name);
    }

    /** @return array{0: User, 1: CompanyBrandVersion, 2: string, 3: string} */
    private function pinned(): array
    {
        $admin = PurchasingActor::make([CompanyDutyManifest::BRANDING]);
        $version = app(SaveCompanyBranding::class)($admin, [], $this->stream($this->fixture('gray.png')), 'Logo')['version'];
        $blob = FileBlob::query()->findOrFail($version->logo_blob_id);
        $path = StoredObject::path($blob->storage_key);

        return [$admin, $version, $path, (string) Storage::disk('files')->get($path)];
    }

    public function test_a_missing_pinned_logo_is_restored_from_identical_bytes_under_the_same_blob(): void
    {
        [$admin, $version, $path, $original] = $this->pinned();
        $logo = app(ReadCompanyBrand::class)->current()->logo;
        Storage::disk('files')->delete($path);
        $this->assertFalse($this->readable($logo));

        $this->assertSame('restored', app(RestoreCompanyBrandLogo::class)->execute($admin, $version->version, $this->stream($original), 'Recovered from backup'));

        $this->assertTrue($this->readable($logo));
        $this->assertSame($original, app(ReadCompanyBrandLogo::class)->execute($logo));
        $this->assertSame($version->logo_blob_id, app(ReadCompanyBrand::class)->current()->logo->blobId, 'the brand still points at the original blob');
        $this->assertSame(1, FileBlob::query()->count());
    }

    public function test_different_bytes_are_refused_even_if_they_are_a_valid_logo(): void
    {
        [$admin, $version, $path] = $this->pinned();
        Storage::disk('files')->delete($path);

        try {
            app(RestoreCompanyBrandLogo::class)->execute($admin, $version->version, $this->stream($this->fixture('rgba.png')), 'Wrong file');
            $this->fail('Expected a refusal.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('logo_restore_restore_mismatch', $e->reasonCode);
        }
        $this->assertFalse(Storage::disk('files')->exists($path));
    }

    public function test_a_new_upload_does_not_repair_the_old_logo(): void
    {
        [$admin, , $path] = $this->pinned();
        $old = app(ReadCompanyBrand::class)->current()->logo;
        Storage::disk('files')->delete($path);
        app(SaveCompanyBranding::class)($admin, [], $this->stream($this->fixture('rgba.png')), 'Newer');

        $this->assertFalse($this->readable($old), 'the pinned original stays unavailable until its own bytes return');
    }

    public function test_an_intact_logo_is_left_alone_and_a_reason_and_the_duty_are_required(): void
    {
        [$admin, $version, , $original] = $this->pinned();
        $restore = app(RestoreCompanyBrandLogo::class);
        $this->assertSame('intact', $restore->execute($admin, $version->version, $this->stream($original), 'Check'));

        try {
            $restore->execute($admin, $version->version, $this->stream($original), '  ');
            $this->fail('Expected a refusal.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('reason', $e->field);
        }
        $this->expectException(DomainInvariantException::class);
        $restore->execute(PurchasingActor::make([]), $version->version, $this->stream($original), 'No duty');
    }

    private function readable(object $logo): bool
    {
        try {
            app(ReadCompanyBrandLogo::class)->execute($logo);

            return true;
        } catch (FileException) {
            return false;
        }
    }
}
