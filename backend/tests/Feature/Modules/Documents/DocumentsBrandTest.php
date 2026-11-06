<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\GenerateDocumentArtefact;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents\PdfInspector;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** Company branding on documents (#23, ADR 0162): pinned at the freeze, drawn from the pinned logo only, and absent exactly as before when there is no brand. */
final class DocumentsBrandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->app->instance(CompanyDutyManifest::class, new CompanyDutyManifest([CompanyDutyManifest::BRANDING => 'ACTIVE']));
        $this->user = PermissionedActor::grant(User::factory()->create(), [CompanyDutyManifest::BRANDING], 1);
        $registry = new DocumentTypeRegistry;
        $registry->register(new PurchaseOrderShapedAdapter(supportsLetter: true));
        $this->app->instance(DocumentTypeRegistry::class, $registry);
    }

    private function brand(array $changes, ?string $fixture = null, string $reason = 'Brand'): void
    {
        $stream = null;
        if ($fixture !== null) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, (string) file_get_contents(__DIR__.'/../../../Fixtures/Branding/'.$fixture));
            rewind($stream);
        }
        app(SaveCompanyBranding::class)($this->user, $changes, $stream, $reason);
    }

    /** @return array{manifest: DocumentManifest, intent: DocumentGenerationIntent} */
    private function bind(string $identity): array
    {
        $r = app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:'.$identity, 1, DocumentManifest::ORIGINAL, $identity, 'key-'.++$this->keys, $this->user->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $r;
    }

    private function pdf(int $intentId): string
    {
        $artefact = app(GenerateDocumentArtefact::class)->execute($intentId, $this->user->id)['artefact'];
        $blob = FileBlob::query()->findOrFail($artefact->blob_id);

        return Storage::disk('files')->get(StoredObject::path($blob->storage_key));
    }

    public function test_no_brand_pins_nothing_and_draws_nothing_extra(): void
    {
        $b = $this->bind('plain');
        $this->assertSame(['NOT_AVAILABLE', null, []], [$b['manifest']->brand_state, $b['manifest']->brand, $b['manifest']->asset_manifest]);
        $bytes = $this->pdf($b['intent']->id);
        $this->assertStringNotContainsString('/XObject', $bytes);
        new PdfInspector($bytes);
    }

    public function test_a_name_and_tagline_without_a_logo_are_pinned_and_printed(): void
    {
        $this->brand(['display_name' => 'XYZ Paints', 'tagline' => 'Colour done right']);
        $b = $this->bind('text');
        $this->assertSame('PINNED', $b['manifest']->brand_state);
        $this->assertEquals(['version' => 1, 'display_name' => 'XYZ Paints', 'tagline' => 'Colour done right'], $b['manifest']->brand);
        $this->assertSame([], $b['manifest']->asset_manifest);
        $bytes = $this->pdf($b['intent']->id);
        $this->assertStringContainsString('(XYZ Paints)', $bytes);
        $this->assertStringContainsString('(Colour done right)', $bytes);
        $this->assertStringNotContainsString('/XObject', $bytes);
        new PdfInspector($bytes);
    }

    /** @return array<string, array{string, bool}> */
    public static function logos(): array
    {
        return ['rgb png' => ['rgb-with-text.png', false], 'rgba png (soft mask)' => ['rgba.png', true], 'grey+alpha png (soft mask)' => ['gray-alpha.png', true], 'grey png' => ['gray.png', false],
            'palette png with transparency (soft mask)' => ['palette-transparent.png', true], 'jpeg' => ['photo-with-exif.jpg', false], 'progressive jpeg' => ['progressive.jpg', false]];
    }

    /** @dataProvider logos */
    public function test_a_pinned_logo_is_embedded_as_an_image_xobject(string $fixture, bool $masked): void
    {
        $this->brand(['display_name' => 'XYZ Paints'], $fixture);
        $b = $this->bind('logo');
        $asset = $b['manifest']->asset_manifest[0];
        $this->assertSame(['logo', 'PINNED'], [$asset['role'], $b['manifest']->brand_state]);
        $bytes = $this->pdf($b['intent']->id);

        $inspected = new PdfInspector($bytes);
        $this->assertStringContainsString('/XObject << /Im1 ', $bytes);
        $this->assertStringContainsString('/Im1 Do', implode("\n", $inspected->contents));
        $this->assertSame($masked, str_contains($bytes, '/SMask'));
        $this->assertStringContainsString(str_ends_with($fixture, '.jpg') ? '/DCTDecode' : '/FlateDecode', $bytes);
        $this->assertSame($asset['sha256'], FileBlob::query()->findOrFail($asset['blob_id'])->sha256);
    }

    public function test_the_manifest_keeps_the_brand_it_pinned_when_the_brand_changes_later(): void
    {
        $this->brand(['display_name' => 'First Name'], 'gray.png');
        $b = $this->bind('pinned');
        $this->brand(['display_name' => 'Second Name'], 'rgba.png', 'Rebrand');
        $this->brand(['display_name' => null, 'tagline' => null, 'remove_logo' => true], null, 'Clear');

        $again = $this->bind('pinned');
        $this->assertSame($b['manifest']->id, $again['manifest']->id, 'the same identity resolves the original freeze whatever the brand has become');
        $bytes = $this->pdf($b['intent']->id);
        $this->assertStringContainsString('(First Name)', $bytes);
        $this->assertStringNotContainsString('Second Name', $bytes);
        $this->assertSame('NOT_AVAILABLE', $this->bind('after-clear')['manifest']->brand_state, 'a cleared brand freezes as no brand');
    }

    public function test_a_pinned_logo_that_is_gone_fails_the_generation_instead_of_printing_unbranded(): void
    {
        $this->brand(['display_name' => 'XYZ Paints'], 'gray.png');
        $b = $this->bind('gone');
        $asset = $b['manifest']->asset_manifest[0];
        Storage::disk('files')->delete(StoredObject::path(FileBlob::query()->findOrFail($asset['blob_id'])->storage_key));

        try {
            $this->pdf($b['intent']->id);
            $this->fail('Expected the generation to fail.');
        } catch (DocumentException $e) {
            $this->assertSame('brand_asset_unavailable', $e->reasonCode);
        }
        $this->assertSame(['FAILED', 'brand_asset_unavailable'], [$b['intent']->refresh()->state, $b['intent']->error_code]);
    }

    public function test_a_long_name_is_cut_to_the_line_not_drawn_off_the_page(): void
    {
        $this->brand(['display_name' => str_repeat('Wide ', 24)], 'rgba.png');
        $bytes = $this->pdf($this->bind('long')['intent']->id);

        $this->assertStringContainsString('...)', $bytes);
    }

    public function test_the_pinned_digest_covers_the_brand(): void
    {
        $plain = $this->bind('digest-a')['manifest']->pinned_manifest_digest;
        $this->brand(['display_name' => 'XYZ Paints']);
        $branded = $this->bind('digest-b')['manifest']->pinned_manifest_digest;

        $this->assertNotSame($plain, $branded);
    }
}
