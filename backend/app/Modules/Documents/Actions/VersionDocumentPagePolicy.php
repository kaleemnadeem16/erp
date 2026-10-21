<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Prepares the next immutable version of a page policy. $typeKey is a registered document type, or '*' for a policy any type may use if its own constraints
 * allow the format (checked again when it is activated or resolved). Internal: no route, no activation.
 */
final class VersionDocumentPagePolicy
{
    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(string $typeKey, string $policyKey, PagePolicyConfiguration $configuration, ?int $actorId = null): DocumentPagePolicyVersion
    {
        if ($typeKey !== '*') {
            $constraints = $this->registry->layoutConstraints($typeKey);
            $configuration->assertFits($constraints);
            if (array_intersect($configuration->compatibleLayouts, $constraints->layouts) === []) {
                throw new DocumentException('The page policy is compatible with no layout this document type allows.', 'compatible_layouts', 'layout_not_allowed');
            }
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,59}$/', $policyKey) !== 1) {
            throw new DocumentException('A policy key is lowercase letters, digits and hyphens.', 'policy_key', 'key_invalid');
        }
        $companyId = $this->company->id();

        return DB::transaction(function () use ($typeKey, $policyKey, $configuration, $actorId, $companyId): DocumentPagePolicyVersion {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:page-policy:{$companyId}:{$typeKey}:{$policyKey}"]);
            $next = 1 + (int) DocumentPagePolicyVersion::query()->where(['type_key' => $typeKey, 'policy_key' => $policyKey])->max('version');

            return DocumentPagePolicyVersion::create([
                'type_key' => $typeKey, 'policy_key' => $policyKey, 'version' => $next,
                'format' => $configuration->format->value, 'orientation' => $configuration->orientation->value,
                'width_mm' => $configuration->widthMm(), 'height_mm' => $configuration->heightMm(),
                'margin_top_mm' => $configuration->marginTopMm, 'margin_right_mm' => $configuration->marginRightMm,
                'margin_bottom_mm' => $configuration->marginBottomMm, 'margin_left_mm' => $configuration->marginLeftMm,
                'first_header_mode' => $configuration->firstHeader->value, 'continuation_header_mode' => $configuration->continuationHeader->value,
                'first_footer_mode' => $configuration->firstFooter->value, 'continuation_footer_mode' => $configuration->continuationFooter->value,
                'compatible_layouts' => $configuration->compatibleLayouts, 'created_by' => $actorId, 'created_at' => now(),
            ])->refresh();
        });
    }
}
