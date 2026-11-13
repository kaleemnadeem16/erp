<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\JobSource;

use App\Support\CompanyContext;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\SourceFamilyApplicability;

/** Generic source/document provenance is not a Job allocation. See the reviewed writer inventory and frozen #122 contract. */
final readonly class InventoryJobSourceApplicability implements SourceFamilyApplicability
{
    public const CONTRACT_VERSION = 'INVENTORY_NO_JOB_BINDING.1';

    /** @var list<string> Every future supported validated Job binding must be registered here before activation. */
    public const SUPPORTED_BINDINGS = [];

    /** @param list<string> $supportedBindings Explicit binding inventory, not stock source types or operator configuration. */
    public function __construct(private array $supportedBindings = self::SUPPORTED_BINDINGS) {}

    public function family(): string
    {
        return 'INVENTORY_MATERIAL';
    }

    public function assess(int $companyId): FamilyAssessment
    {
        if ($companyId < 1 || $companyId !== app(CompanyContext::class)->id()) {
            return FamilyAssessment::unknown($this->family(), self::CONTRACT_VERSION, 'The requested company is outside the current source context.');
        }

        return $this->supportedBindings === []
            ? FamilyAssessment::notApplicable($this->family(), self::CONTRACT_VERSION)
            : FamilyAssessment::applicable($this->family(), self::CONTRACT_VERSION, $this->supportedBindings);
    }
}
