<?php

namespace Tests\Unit\Modules\Tax;

use App\Modules\Tax\Actions\DetermineQuotationTax;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxSupport;
use PHPUnit\Framework\TestCase;

class TaxClassificationTest extends TestCase
{
    public function test_goods_and_services_use_their_declared_nature_defaults(): void
    {
        foreach ([[TaxNature::Goods, 'goods'], [TaxNature::Services, 'services']] as [$nature, $code]) {
            $resolved = TaxClassification::resolve(SourceKind::Item, $nature, null, null, 'goods', 'services');
            $this->assertSame($code, $resolved->code);
            $this->assertSame('company_nature_default', $resolved->provenance);
            $this->assertSame('explicit', TaxClassification::resolve(SourceKind::Item, $nature, 'explicit', 'category', 'goods', 'services')->code);
            $this->assertSame('category', TaxClassification::resolve(SourceKind::Item, $nature, null, 'category', 'goods', 'services')->code);
        }
    }

    public function test_manual_requires_explicit_facts_and_assembly_uses_services_default(): void
    {
        $manual = TaxClassification::resolve(SourceKind::Manual, null, null, null, 'goods', 'services');
        $this->assertSame(TaxSupport::Unknown, (new DetermineQuotationTax)($manual)->support);
        $assembly = TaxClassification::resolve(SourceKind::Assembly, null, null, null, 'goods', 'services');
        $this->assertSame('services', $assembly->code);
        $this->assertSame(TaxNature::Services, $assembly->nature);
        $explicit = TaxClassification::resolve(SourceKind::Manual, TaxNature::Goods, 'declared', null, null, null);
        $result = (new DetermineQuotationTax)($explicit);
        $this->assertSame(TaxSupport::Supported, $result->support);
        $this->assertSame(TaxDisposition::Unknown, $result->disposition);
        $this->assertStringContainsString('CALCULATION_PENDING', $result->reason);
    }

    public function test_ambiguous_nature_is_unsupported_instead_of_no_tax(): void
    {
        $result = (new DetermineQuotationTax)(new TaxClassification(SourceKind::Item, TaxNature::Both, 'both', 'explicit'));
        $this->assertSame(TaxSupport::Unsupported, $result->support);
        $this->assertSame(TaxDisposition::Unknown, $result->disposition);
    }
}
