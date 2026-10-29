<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\ActivateDocumentConfiguration;
use App\Modules\Documents\Actions\VersionDocumentPagePolicy;
use App\Modules\Documents\Actions\VersionDocumentTemplate;
use App\Modules\Documents\Models\DocumentCompanyDefault;
use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\PageBandMode;
use App\Modules\Documents\Support\PageFormat;
use App\Modules\Documents\Support\PageOrientation;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Modules\Documents\Support\TemplateConfiguration;
use App\Modules\Sales\Support\Documents\QuotationCustomerDocumentAdapter;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Document print configuration (DF0/DF1a) as XYZ Paints might set it up, through the module's own Actions. Only configuration is seeded: Documents has no route,
 * duty or UI yet, and the only registered document type (the customer quotation, `sales.quotation.customer`) is authorised by `sales.document.generate`, a STAGED duty
 * nobody holds. So no subject binding, manifest, generation intent or stored file exists in the demo, and no branding asset exists either (the brand is always NOT_AVAILABLE).
 *
 * Scenarios:
 *  - Two template lines for the quotation: `demo-standard` (the standard table, terms kept) and `demo-compact` (the compact table), each at version 1.
 *  - Three page policies: `demo-a4` and `demo-letter`, Company-wide (type `*`), and `demo-quotation-a4` for the quotation type. The quotation type is A4-only (it has not opted
 *    into Letter), so a Letter policy is stored Company-wide but can never be activated for, or resolved onto, a quotation: the type's constraint wins.
 *  - The quotation type's default is activated once (standard template plus its A4 policy) at generation 1, so policy resolution has an answer. The Company-wide default is left unset on
 *    purpose, so the resolver falls back to the shipped default there.
 * Versions are insert-only, so each line is created only when its key does not yet exist, and the default is activated only while the type has none: a rerun adds nothing.
 */
final class DocumentConfigurationDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $type = QuotationCustomerDocumentAdapter::KEY;

        $standard = $this->template($type, 'demo-standard', new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines'], true, true), $actor);
        $this->template($type, 'demo-compact', new TemplateConfiguration(DocumentLayouts::COMPACT, ['lines', 'terms'], ['lines'], true, false), $actor);

        $page = fn (PageFormat $format, string $margin): PagePolicyConfiguration => new PagePolicyConfiguration(
            $format, PageOrientation::Portrait, $margin, $margin, $margin, $margin,
            PageBandMode::Full, PageBandMode::Compact, PageBandMode::PageNumberOfTotal, PageBandMode::PageNumber, DocumentLayouts::all(),
        );
        $this->policy('*', 'demo-a4', $page(PageFormat::A4, '20'), $actor);
        $this->policy('*', 'demo-letter', $page(PageFormat::Letter, '25'), $actor);
        $quotationA4 = $this->policy($type, 'demo-quotation-a4', $page(PageFormat::A4, '15'), $actor);

        if (! DocumentCompanyDefault::query()->where('type_key', $type)->exists()) {
            app(ActivateDocumentConfiguration::class)->execute($type, $standard->id, $quotationA4->id, 0, $actor->id, 'Demo: the quotation default');
        }
    }

    private function template(string $type, string $key, TemplateConfiguration $configuration, User $actor): DocumentTemplateVersion
    {
        return DocumentTemplateVersion::query()->where(['type_key' => $type, 'template_key' => $key])->orderBy('version')->first()
            ?? app(VersionDocumentTemplate::class)->execute($type, $key, $configuration, $actor->id);
    }

    private function policy(string $type, string $key, PagePolicyConfiguration $configuration, User $actor): DocumentPagePolicyVersion
    {
        return DocumentPagePolicyVersion::query()->where(['type_key' => $type, 'policy_key' => $key])->orderBy('version')->first()
            ?? app(VersionDocumentPagePolicy::class)->execute($type, $key, $configuration, $actor->id);
    }
}
