<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentCompanyDefault;
use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\LayoutConstraints;
use App\Modules\Documents\Support\PageBandMode;
use App\Modules\Documents\Support\PageOrientation;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Modules\Documents\Support\ResolvedComponent;
use App\Modules\Documents\Support\ResolvedDocumentPolicy;
use App\Modules\Documents\Support\TemplateConfiguration;

/**
 * Resolves the template and page policy for a document type: shipped default, then the Company-wide ('*') default, then the type's own default, then an
 * explicit choice. A default that the type's constraints or the already-resolved layout do not allow is skipped and noted (the type overrides it); an
 * explicit choice that does not fit is refused. Reads current configuration only: no user preference, locale or request input can change the result, and
 * the brand is reported NOT_AVAILABLE, never substituted. Pure read; writes nothing.
 */
final class ResolveDocumentPolicy
{
    public function __construct(private readonly DocumentTypeRegistry $registry) {}

    public function execute(string $typeKey, ?int $explicitTemplateVersionId = null, ?int $explicitPagePolicyVersionId = null): ResolvedDocumentPolicy
    {
        $constraints = $this->registry->layoutConstraints($typeKey);
        $notes = [];
        $typeDefault = DocumentCompanyDefault::query()->where('type_key', $typeKey)->first();
        $companyDefault = DocumentCompanyDefault::query()->where('type_key', '*')->first();

        $template = $this->shippedTemplate($constraints);
        if ($typeDefault?->active_template_version_id !== null) {
            $version = DocumentTemplateVersion::query()->findOrFail($typeDefault->active_template_version_id);
            try {
                $version->configuration()->assertFits($constraints);
                $template = $this->templateComponent('TYPE', $version);
            } catch (DocumentException $e) {
                $notes[] = "Type default template '{$version->template_key}' v{$version->version} skipped: {$e->getMessage()}";
            }
        }
        if ($explicitTemplateVersionId !== null) {
            $version = DocumentTemplateVersion::query()->find($explicitTemplateVersionId)
                ?? throw new DocumentException('That template version does not exist for this company.', 'template', 'version_not_found');
            if ($version->type_key !== $typeKey) {
                throw new DocumentException('That template belongs to another document type.', 'template', 'type_mismatch');
            }
            $version->configuration()->assertFits($constraints);
            $template = $this->templateComponent('EXPLICIT', $version);
        }
        /** @var TemplateConfiguration $templateConfiguration */
        $templateConfiguration = $template->configuration;

        $page = $this->shippedPagePolicy($constraints);
        foreach ([['COMPANY', $companyDefault], ['TYPE', $typeDefault]] as [$source, $default]) {
            if ($default?->active_page_policy_version_id === null) {
                continue;
            }
            $version = DocumentPagePolicyVersion::query()->findOrFail($default->active_page_policy_version_id);
            try {
                $this->assertPageFits($typeKey, $version, $constraints, $templateConfiguration);
                $page = $this->pageComponent($source, $version);
            } catch (DocumentException $e) {
                $notes[] = "{$source} default page policy '{$version->policy_key}' v{$version->version} skipped: {$e->getMessage()}";
            }
        }
        if ($explicitPagePolicyVersionId !== null) {
            $version = DocumentPagePolicyVersion::query()->find($explicitPagePolicyVersionId)
                ?? throw new DocumentException('That page-policy version does not exist for this company.', 'page_policy', 'version_not_found');
            $this->assertPageFits($typeKey, $version, $constraints, $templateConfiguration);
            $page = $this->pageComponent('EXPLICIT', $version);
        }

        return new ResolvedDocumentPolicy($typeKey, $template, $page, $notes);
    }

    private function assertPageFits(string $typeKey, DocumentPagePolicyVersion $version, LayoutConstraints $constraints, TemplateConfiguration $template): void
    {
        if (! in_array($version->type_key, [$typeKey, '*'], true)) {
            throw new DocumentException('That page policy belongs to another document type.', 'page_policy', 'type_mismatch');
        }
        $version->configuration()->assertFits($constraints);
        if (! in_array($template->layoutId, $version->compatible_layouts, true)) {
            throw new DocumentException("The page policy is not compatible with layout '{$template->layoutId}'.", 'page_policy', 'incompatible');
        }
    }

    private function templateComponent(string $source, DocumentTemplateVersion $v): ResolvedComponent
    {
        return new ResolvedComponent($source, $v->id, $v->template_key, $v->version, $v->configuration());
    }

    private function pageComponent(string $source, DocumentPagePolicyVersion $v): ResolvedComponent
    {
        return new ResolvedComponent($source, $v->id, $v->policy_key, $v->version, $v->configuration());
    }

    /** The code default: the first allowed curated layout showing exactly the type's own sections. Always fits the type. */
    private function shippedTemplate(LayoutConstraints $constraints): ResolvedComponent
    {
        $layout = in_array(DocumentLayouts::STANDARD, $constraints->layouts, true) ? DocumentLayouts::STANDARD : $constraints->layouts[0];

        return new ResolvedComponent('SHIPPED', null, 'shipped', 0, new TemplateConfiguration($layout, $constraints->permittedSections, $constraints->mandatorySections));
    }

    /** The code default: portrait on the type's first allowed paper, compatible with every curated layout. */
    private function shippedPagePolicy(LayoutConstraints $constraints): ResolvedComponent
    {
        return new ResolvedComponent('SHIPPED', null, 'shipped', 0, new PagePolicyConfiguration(
            $constraints->formats[0], PageOrientation::Portrait, '20', '20', '20', '20',
            PageBandMode::Full, PageBandMode::Compact, PageBandMode::PageNumberOfTotal, PageBandMode::PageNumberOfTotal, DocumentLayouts::all(),
        ));
    }
}
