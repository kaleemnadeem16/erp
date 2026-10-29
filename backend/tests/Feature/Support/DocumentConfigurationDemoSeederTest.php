<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Documents\Models\DocumentCompanyDefault;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Sales\Support\Documents\QuotationCustomerDocumentAdapter;
use Database\Seeders\Demo\Documents\DocumentConfigurationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Documents\Actions\ActivateDocumentConfiguration;
use App\Modules\Documents\Support\DocumentException;
use Tests\TestCase;

/** DocumentConfigurationDemoSeeder: templates and page policies exist once, the quotation default is active at generation 1, a rerun adds nothing and no binding/file exists. */
final class DocumentConfigurationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_configuration_exists_once_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->seed(DocumentConfigurationDemoSeeder::class);

        $this->assertEqualsCanonicalizing(['demo-standard', 'demo-compact'], DocumentTemplateVersion::query()->pluck('template_key')->all());
        $this->assertSame(1, (int) DocumentTemplateVersion::query()->max('version'));
        $this->assertEqualsCanonicalizing(['A4', 'LETTER', 'A4'], DocumentPagePolicyVersion::query()->pluck('format')->all());
        $default = DocumentCompanyDefault::query()->where('type_key', QuotationCustomerDocumentAdapter::KEY)->sole();
        $this->assertSame(1, $default->generation);
        $this->assertSame(0, DocumentCompanyDefault::query()->where('type_key', '*')->count(), 'No Company-wide default is chosen.');
        $letter = DocumentPagePolicyVersion::query()->where('policy_key', 'demo-letter')->sole();
        try {
            app(ActivateDocumentConfiguration::class)->execute(QuotationCustomerDocumentAdapter::KEY, null, $letter->id, 1);
            $this->fail('A quotation cannot use a Letter policy.');
        } catch (DocumentException $refusal) {
            $this->assertSame('format_not_allowed', $refusal->reasonCode);
        }
        $this->assertSame(0, DocumentManifest::query()->count() + FileBlob::query()->count(), 'No binding, manifest or file: generation is staged.');
    }
}
