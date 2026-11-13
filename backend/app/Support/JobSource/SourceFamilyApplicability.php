<?php

declare(strict_types=1);

namespace App\Support\JobSource;

/**
 * The source-owner side of Job source applicability (Phase 5 Batch 1, #122). One implementation per source FAMILY, registered by the module that owns that family
 * (Inventory, Purchasing). The Sales release proof asks every required family; a family that is not registered, or that cannot prove its answer, is UNKNOWN.
 *
 * FROZEN BOUNDARY (owner direction, #122): generic source provenance (Inventory `source_type`/`source_id`, rejected-exit `document_type`/`document_id`, PO/receipt
 * identities) is PROVENANCE, never an authoritative Job allocation. Only an explicitly supported and validated Job binding can create a Job material/cost obligation.
 * NOT_APPLICABLE therefore means: this family has NO supported validated Job binding that could attach an obligation to the lineage, proven against the family's
 * own current writer set, not merely absent foreign keys. There is no global source-type allowlist and ordinary stock/purchasing workflows are unchanged.
 */
interface SourceFamilyApplicability
{
    /** Stable family code, e.g. INVENTORY_MATERIAL or PURCHASING_SUPPLY. */
    public function family(): string;

    /** Assess this family for ONE company; never throws for "cannot prove" — that is UNKNOWN. */
    public function assess(int $companyId): FamilyAssessment;
}
