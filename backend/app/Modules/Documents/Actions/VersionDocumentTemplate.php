<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\TemplateConfiguration;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Prepares the next immutable version of a template for a registered document type. The curated, typed configuration must fit the type's constraints
 * (permitted and mandatory sections, allowed layouts); versions are numbered under a lock and never edited afterwards. Internal: no route, no activation.
 */
final class VersionDocumentTemplate
{
    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(string $typeKey, string $templateKey, TemplateConfiguration $configuration, ?int $actorId = null): DocumentTemplateVersion
    {
        $constraints = $this->registry->layoutConstraints($typeKey);
        $configuration->assertFits($constraints);
        if (preg_match('/^[a-z][a-z0-9-]{0,59}$/', $templateKey) !== 1) {
            throw new DocumentException('A template key is lowercase letters, digits and hyphens.', 'template_key', 'key_invalid');
        }
        $companyId = $this->company->id();

        return DB::transaction(function () use ($typeKey, $templateKey, $configuration, $actorId, $companyId): DocumentTemplateVersion {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:template:{$companyId}:{$typeKey}:{$templateKey}"]);
            $next = 1 + (int) DocumentTemplateVersion::query()->where(['type_key' => $typeKey, 'template_key' => $templateKey])->max('version');

            return DocumentTemplateVersion::create([
                'type_key' => $typeKey, 'template_key' => $templateKey, 'version' => $next, 'layout_id' => $configuration->layoutId,
                'schema_version' => TemplateConfiguration::SCHEMA_VERSION,
                'permitted_sections' => $configuration->permittedSections, 'mandatory_sections' => $configuration->mandatorySections,
                'repeat_table_header' => $configuration->repeatTableHeader, 'keep_rows_together' => $configuration->keepRowsTogether,
                'created_by' => $actorId, 'created_at' => now(),
            ])->refresh();
        });
    }
}
