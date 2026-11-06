<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Company;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Company\Actions\ReadCompanyBrand;
use App\Modules\Company\Actions\ReadCompanyBrandLogo;
use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Models\CompanyChange;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\StoredObject;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * Company branding (issue #23, ADR 0162): an immutable, numbered brand per company with an optional PNG/JPEG logo stored through Files. The duty is STAGED, so nobody can change
 * the brand until it is activated; these tests switch it on in the container only and give the permission to one person, never through any shipped grant.
 */
final class CompanyBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Branding/'.$name);
    }

    private function stream(string $bytes)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }

    private function activate(): void
    {
        $this->app->instance(CompanyDutyManifest::class, new CompanyDutyManifest([CompanyDutyManifest::BRANDING => 'ACTIVE']));
    }

    private function manager(): User
    {
        $this->activate();

        return PurchasingActor::make([CompanyDutyManifest::BRANDING]);
    }

    private function upload(string $name, string $type = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'brand');
        file_put_contents($path, $this->fixture($name));

        return new UploadedFile($path, $name, $type, null, true);
    }

    // ---- the duty is STAGED ------------------------------------------------------------------------------------------------------------------------

    public function test_the_duty_is_staged_not_registered_and_unusable_even_with_a_stray_permission_row(): void
    {
        $this->assertSame('STAGED', (new CompanyDutyManifest)->statuses()[CompanyDutyManifest::BRANDING]);
        $this->assertNotContains(CompanyDutyManifest::BRANDING, app(PermissionRegistry::class)->all(), 'a STAGED duty is outside the vocabulary: no role editor can hand it out');
        $holder = PurchasingActor::make([CompanyDutyManifest::BRANDING]);

        $this->actingAs($holder)->postJson('/api/v1/company/branding', ['display_name' => 'X', 'reason' => 'Because'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SaveCompanyBranding::class)($holder, ['display_name' => 'X'], null, 'Because');
    }

    public function test_activating_the_manifest_alone_grants_nothing(): void
    {
        $this->activate();
        $nobody = PurchasingActor::make(['company.profile.manage', 'company.settings.manage', 'company.history.view']);

        $this->actingAs($nobody)->postJson('/api/v1/company/branding', ['display_name' => 'X', 'reason' => 'Because'])->assertForbidden();
        $this->getJson('/api/v1/company/branding')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.can_manage', false);
    }

    public function test_anyone_signed_in_reads_the_brand_but_an_anonymous_caller_cannot(): void
    {
        $this->getJson('/api/v1/company/branding')->assertUnauthorized();
        $this->actingAs(PurchasingActor::make([]))->getJson('/api/v1/company/branding')->assertOk()
            ->assertJsonPath('data.version', null)->assertJsonPath('data.logo', null)->assertJsonPath('data.enabled', false)->assertJsonPath('data.can_manage', false);
        $this->getJson('/api/v1/company/branding/logo')->assertNotFound();
    }

    // ---- saving ------------------------------------------------------------------------------------------------------------------------------------

    public function test_text_and_logo_make_numbered_versions_and_an_unchanged_save_makes_none(): void
    {
        $admin = $this->manager();
        $this->actingAs($admin);

        $this->post('/api/v1/company/branding', ['display_name' => '  XYZ   Paints ', 'tagline' => 'Colour done right', 'reason' => 'Initial branding'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.display_name', 'XYZ Paints')->assertJsonPath('data.tagline', 'Colour done right')->assertJsonPath('data.can_manage', true);
        $this->post('/api/v1/company/branding', ['display_name' => 'XYZ Paints', 'reason' => 'Retry of the same change'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, CompanyBrandVersion::query()->count(), 'a retried or unchanged save never makes a second version');

        $this->post('/api/v1/company/branding', ['logo' => $this->upload('rgba.png'), 'reason' => 'Add the logo'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.display_name', 'XYZ Paints')->assertJsonPath('data.logo.media_type', 'image/png')
            ->assertJsonPath('data.logo.width', 48)->assertJsonPath('data.logo.height', 24);
        $logo = $this->get('/api/v1/company/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith("\x89PNG", $logo->getContent());

        $this->post('/api/v1/company/branding', ['remove_logo' => '1', 'reason' => 'Remove the logo'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.logo', null);
        $this->get('/api/v1/company/branding/logo')->assertNotFound();
        $this->assertSame([1, 2, 3], CompanyBrandVersion::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame(3, CompanyChange::query()->where('section', 'BRANDING')->count(), 'every real change is in the company history');
    }

    public function test_a_reason_is_required_and_the_text_is_plain_and_bounded(): void
    {
        $this->actingAs($this->manager());
        $this->postJson('/api/v1/company/branding', ['display_name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/company/branding', ['display_name' => str_repeat('x', 121), 'reason' => 'Too long'])->assertUnprocessable()->assertJsonValidationErrors('display_name');
        $this->postJson('/api/v1/company/branding', ['tagline' => str_repeat('x', 161), 'reason' => 'Too long'])->assertUnprocessable()->assertJsonValidationErrors('tagline');
        $this->expectException(DomainInvariantException::class);
        app(SaveCompanyBranding::class)(User::query()->orderByDesc('id')->firstOrFail(), ['display_name' => "bad\x07name"], null, 'Control character');
    }

    public function test_clearing_everything_is_a_version_that_reads_as_no_brand(): void
    {
        $admin = $this->manager();
        $save = app(SaveCompanyBranding::class);
        $save($admin, ['display_name' => 'Brand', 'tagline' => 'Tag'], null, 'First');
        $save($admin, ['display_name' => null, 'tagline' => ''], null, 'Clear it');

        $this->assertSame(2, app(ReadCompanyBrand::class)->latest()?->version);
        $this->assertNull(app(ReadCompanyBrand::class)->current(), 'a cleared brand is no brand, not the previous one');
        $this->assertNotNull(app(ReadCompanyBrand::class)->version(1));
        $this->actingAs($admin)->getJson('/api/v1/company/branding')->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.display_name', null);
    }

    // ---- the logo ----------------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function acceptedLogos(): array
    {
        return ['rgb png with text metadata' => ['rgb-with-text.png', 'image/png'], 'grey png' => ['gray.png', 'image/png'], 'palette png with transparency' => ['palette-transparent.png', 'image/png'],
            'grey+alpha png' => ['gray-alpha.png', 'image/png'], 'jpeg with exif and comment' => ['photo-with-exif.jpg', 'image/jpeg'], 'progressive jpeg' => ['progressive.jpg', 'image/jpeg'], 'grey jpeg' => ['gray.jpg', 'image/jpeg']];
    }

    /** @dataProvider acceptedLogos */
    public function test_an_accepted_logo_is_stored_sanitised_and_verified(string $file, string $type): void
    {
        $admin = $this->manager();
        $version = app(SaveCompanyBranding::class)($admin, [], $this->stream($this->fixture($file)), 'Logo')['version'];

        $this->assertSame($type, $version->logo_media_type);
        $blob = FileBlob::query()->findOrFail($version->logo_blob_id);
        $this->assertSame('VERIFIED', $blob->health);
        $stored = Storage::disk('files')->get(StoredObject::path($blob->storage_key));
        $this->assertSame(hash('sha256', $stored), $version->logo_sha256);
        foreach (['Secret Person', 'GPS 31.5', 'SecretCamera', 'copyright secret', 'hidden comment', 'tEXt', 'Exif'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored, "metadata '{$leak}' must not survive sanitising");
        }
        [$w, $h] = getimagesizefromstring($stored);
        $this->assertSame([$version->logo_width, $version->logo_height], [$w, $h], 'the rebuilt file still decodes to the measured size');
        $this->assertSame($stored, app(ReadCompanyBrandLogo::class)->execute(app(ReadCompanyBrand::class)->current()->logo));
    }

    /** @return array<string, array{string, string}> */
    public static function refusedLogos(): array
    {
        return ['svg' => ['logo.svg', 'logo_type_unsupported'], 'gif' => ['logo.gif', 'logo_type_unsupported'], 'cmyk jpeg' => ['cmyk.jpg', 'logo_cmyk'], 'interlaced png' => ['interlaced.png', 'logo_interlaced'],
            '16-bit png' => ['sixteen-bit.png', 'logo_16_bit'], 'too many pixels' => ['too-many-pixels.png', 'image_too_large']];
    }

    /** @dataProvider refusedLogos */
    public function test_a_logo_outside_the_supported_subset_is_refused_with_a_reason_and_nothing_is_written(string $file, string $code): void
    {
        $admin = $this->manager();
        try {
            app(SaveCompanyBranding::class)($admin, [], $this->stream($this->fixture($file)), 'Logo');
            $this->fail('Expected a refusal.');
        } catch (DomainInvariantException $e) {
            $this->assertSame($code, $e->reasonCode);
            $this->assertSame('logo', $e->field);
        }
        $this->assertSame(0, CompanyBrandVersion::query()->count());
        $this->assertSame(0, FileBlob::query()->count());
    }

    public function test_damaged_and_oversized_logos_are_refused(): void
    {
        $admin = $this->manager();
        $png = $this->fixture('gray.png');
        foreach ([substr($png, 0, 40), str_replace('IDAT', 'IDAT', substr($png, 0, 60)).'garbage', 'not an image at all'] as $bad) {
            try {
                app(SaveCompanyBranding::class)($admin, [], $this->stream($bad), 'Logo');
                $this->fail('Expected a refusal.');
            } catch (DomainInvariantException $e) {
                $this->assertContains($e->reasonCode, ['logo_damaged', 'logo_type_unsupported']);
            }
        }
        $this->expectExceptionMessage('larger than 2 MB');
        app(SaveCompanyBranding::class)($admin, [], $this->stream($png.str_repeat("\0", 2_097_153)), 'Logo');
    }

    public function test_the_request_rejects_a_logo_over_two_megabytes_by_validation(): void
    {
        $this->actingAs($this->manager());
        $path = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($path, str_repeat('a', 2_200_000));

        $this->post('/api/v1/company/branding', ['logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true), 'reason' => 'Too big'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_replacing_the_logo_keeps_the_earlier_version_and_its_file(): void
    {
        $admin = $this->manager();
        $save = app(SaveCompanyBranding::class);
        $one = $save($admin, [], $this->stream($this->fixture('gray.png')), 'First')['version'];
        $two = $save($admin, [], $this->stream($this->fixture('rgba.png')), 'Second')['version'];

        $this->assertNotSame($one->logo_blob_id, $two->logo_blob_id);
        $old = app(ReadCompanyBrand::class)->version(1);
        $this->assertSame('VERIFIED', FileBlob::query()->findOrFail($old->logo->blobId)->health);
        $this->assertNotSame('', app(ReadCompanyBrandLogo::class)->execute($old->logo), 'a version that was pinned by a document can always read its own logo back');
    }

    public function test_a_logo_whose_object_changed_or_vanished_is_unavailable_never_served(): void
    {
        $admin = $this->manager();
        $version = app(SaveCompanyBranding::class)($admin, [], $this->stream($this->fixture('gray.png')), 'Logo')['version'];
        $blob = FileBlob::query()->findOrFail($version->logo_blob_id);
        $path = StoredObject::path($blob->storage_key);
        $logo = app(ReadCompanyBrand::class)->current()->logo;

        Storage::disk('files')->put($path, 'tampered');
        $this->assertSame('file_unavailable', $this->refusal(fn () => app(ReadCompanyBrandLogo::class)->execute($logo)));
        Storage::disk('files')->delete($path);
        $this->assertSame('file_unavailable', $this->refusal(fn () => app(ReadCompanyBrandLogo::class)->execute($logo)));
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (FileException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a FileException.');
    }

    // ---- history and tenancy -----------------------------------------------------------------------------------------------------------------------

    public function test_versions_are_history_the_model_and_the_database_refuse_changes(): void
    {
        $admin = $this->manager();
        $version = app(SaveCompanyBranding::class)($admin, ['display_name' => 'Brand'], null, 'First')['version'];

        $this->assertRefused(fn () => $version->update(['display_name' => 'Changed']));
        $this->assertRefused(fn () => $version->delete());
        $this->assertRefused(fn () => DB::table('company_brand_versions')->where('id', $version->id)->update(['display_name' => 'Changed']));
        $this->assertRefused(fn () => DB::table('company_brand_versions')->where('id', $version->id)->delete());
        $this->assertRefused(fn () => DB::statement('TRUNCATE company_brand_versions'));
        $this->assertSame('Brand', $version->fresh()->display_name);
    }

    private function assertRefused(callable $change): void
    {
        try {
            DB::transaction($change);
        } catch (DomainInvariantException|QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The change must be refused.');
    }

    public function test_another_companys_brand_and_blob_are_invisible_and_unreferenceable(): void
    {
        $admin = $this->manager();
        $other = Company::query()->withoutGlobalScopes()->create(['name' => 'Other Co']);
        $otherUser = (object) ['id' => DB::table('users')->insertGetId(array_merge(User::factory()->make()->getAttributes(), ['company_id' => $other->id, 'email' => 'rival@example.test', 'password' => 'x']))];
        DB::table('company_brand_versions')->insert(['company_id' => $other->id, 'version' => 1, 'display_name' => 'Rival', 'reason' => 'Theirs', 'created_by' => $otherUser->id, 'created_at' => now()]);
        $blobId = DB::table('file_blobs')->insertGetId(['company_id' => $other->id, 'storage_disk' => 'files', 'storage_key' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64),
            'size_bytes' => 10, 'detected_media_type' => 'image/png', 'health' => 'VERIFIED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $otherUser->id, 'created_at' => now()]);

        $this->actingAs($admin)->getJson('/api/v1/company/branding')->assertOk()->assertJsonPath('data.version', null)->assertJsonPath('data.display_name', null);
        $this->assertNull(app(ReadCompanyBrand::class)->current());
        $this->assertNull(app(ReadCompanyBrand::class)->version(1));
        try {
            DB::table('company_brand_versions')->insert(['company_id' => 1, 'version' => 1, 'logo_blob_id' => $blobId, 'logo_sha256' => str_repeat('b', 64), 'logo_media_type' => 'image/png',
                'logo_width' => 1, 'logo_height' => 1, 'logo_size_bytes' => 10, 'reason' => 'Cross-company', 'created_by' => $admin->id, 'created_at' => now()]);
            $this->fail('A brand version must not reference another company\'s file.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('company_brand_versions_blob_fk', $e->getMessage());
        }
    }
}
