<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentCompanyDefault;
use App\Modules\Documents\Models\DocumentDefaultActivation;
use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Makes a template and/or page-policy version the Company's default for a document type ('*' = every type; a '*' default carries a page policy only).
 * Generation-locked: the caller names the generation it saw (0 = none yet) and a stale one is refused. The new choice must fit the type's constraints and be
 * layout-compatible. Moving to a lower version of the same template or policy is a downgrade: it needs a reason and is refused while live documents still pin
 * the version being left (DocumentConfigurationUsage). Every activation is recorded in an append-only event the database ties to the default.
 *
 * Internal: no route and no duty yet. A public configuration slice must put its guarded request in front of this.
 */
final class ActivateDocumentConfiguration
{
    public function __construct(
        private readonly DocumentTypeRegistry $registry,
        private readonly DocumentConfigurationUsage $usage,
        private readonly CompanyContext $company,
    ) {}

    public function execute(string $typeKey, ?int $templateVersionId, ?int $pagePolicyVersionId, int $expectedGeneration, ?int $actorId = null, ?string $reason = null): DocumentCompanyDefault
    {
        if ($templateVersionId === null && $pagePolicyVersionId === null) {
            throw new DocumentException('Name a template or page-policy version to activate.', null, 'nothing_to_activate');
        }
        if ($typeKey === '*' && $templateVersionId !== null) {
            throw new DocumentException('A Company-wide default carries a page policy only; a template belongs to one document type.', 'template', 'template_not_generic');
        }
        $constraints = $typeKey === '*' ? null : $this->registry->layoutConstraints($typeKey);
        $companyId = $this->company->id();
        $reason = $reason === null ? null : trim($reason);

        return DB::transaction(function () use ($typeKey, $templateVersionId, $pagePolicyVersionId, $expectedGeneration, $actorId, $reason, $constraints, $companyId): DocumentCompanyDefault {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:default:{$companyId}:{$typeKey}"]);
            $current = DocumentCompanyDefault::query()->where('type_key', $typeKey)->first();
            if (($current === null ? 0 : $current->generation) !== $expectedGeneration) {
                throw new DocumentException('The Company default changed since it was read; reload and try again.', 'generation', 'generation_conflict');
            }

            $template = $templateVersionId === null ? null : DocumentTemplateVersion::query()->find($templateVersionId)
                ?? throw new DocumentException('That template version does not exist for this company.', 'template', 'version_not_found');
            $page = $pagePolicyVersionId === null ? null : DocumentPagePolicyVersion::query()->find($pagePolicyVersionId)
                ?? throw new DocumentException('That page-policy version does not exist for this company.', 'page_policy', 'version_not_found');
            if ($template !== null && $template->type_key !== $typeKey) {
                throw new DocumentException('That template belongs to another document type.', 'template', 'type_mismatch');
            }
            if ($page !== null && ! in_array($page->type_key, [$typeKey, '*'], true)) {
                throw new DocumentException('That page policy belongs to another document type.', 'page_policy', 'type_mismatch');
            }

            $nextTemplate = $template ?? ($current?->active_template_version_id === null ? null : DocumentTemplateVersion::query()->findOrFail($current->active_template_version_id));
            $nextPage = $page ?? ($current?->active_page_policy_version_id === null ? null : DocumentPagePolicyVersion::query()->findOrFail($current->active_page_policy_version_id));
            if ($constraints !== null) {
                $nextTemplate?->configuration()->assertFits($constraints);
                $nextPage?->configuration()->assertFits($constraints);
            }
            if ($nextTemplate !== null && $nextPage !== null && ! in_array($nextTemplate->layout_id, $nextPage->compatible_layouts, true)) {
                throw new DocumentException('The page policy is not compatible with the template layout.', 'page_policy', 'incompatible');
            }
            if (($template === null || $template->id === $current?->active_template_version_id) && ($page === null || $page->id === $current?->active_page_policy_version_id)) {
                throw new DocumentException('That version is already the active default.', null, 'no_change');
            }

            $isDowngrade = false;
            foreach ([['template', $template, $current?->active_template_version_id], ['page_policy', $page, $current?->active_page_policy_version_id]] as [$kind, $new, $activeId]) {
                if ($new === null || $activeId === null || $new->id === $activeId) {
                    continue;
                }
                $active = $kind === 'template' ? DocumentTemplateVersion::query()->findOrFail($activeId) : DocumentPagePolicyVersion::query()->findOrFail($activeId);
                $sameLine = $kind === 'template' ? $active->template_key === $new->template_key : $active->policy_key === $new->policy_key;
                if ($sameLine && $new->version < $active->version) {
                    if ($reason === null || $reason === '') {
                        throw new DocumentException('Moving to an older version needs a reason.', 'reason', 'downgrade_needs_reason');
                    }
                    if ($this->usage->isPinned($companyId, $kind, $active->id)) {
                        throw new DocumentException('Live documents still depend on the version being replaced; the downgrade is refused.', $kind, 'version_pinned');
                    }
                    $isDowngrade = true;
                }
            }

            $generation = ($current === null ? 0 : $current->generation) + 1;
            DocumentDefaultActivation::create([
                'type_key' => $typeKey, 'generation' => $generation,
                'template_version_id' => $nextTemplate?->id, 'page_policy_version_id' => $nextPage?->id,
                'previous_template_version_id' => $current?->active_template_version_id, 'previous_page_policy_version_id' => $current?->active_page_policy_version_id,
                'is_downgrade' => $isDowngrade, 'actor_id' => $actorId, 'reason' => $reason === '' ? null : $reason, 'activated_at' => now(),
            ]);
            $values = ['generation' => $generation, 'active_template_version_id' => $nextTemplate?->id, 'active_page_policy_version_id' => $nextPage?->id, 'updated_at' => now()];
            if ($current === null) {
                return DocumentCompanyDefault::create(['type_key' => $typeKey, ...$values])->refresh();
            }
            $current->update($values);

            return $current->refresh();
        });
    }
}
