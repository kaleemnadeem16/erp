<?php

namespace Tests\Unit\Support;

use App\Support\CompanyContext;
use PHPUnit\Framework\TestCase;

class CompanyContextTest extends TestCase
{
    public function test_id_resolves_to_company_one(): void
    {
        $this->assertSame(1, (new CompanyContext)->id());
    }

    public function test_cache_key_is_namespaced_by_company(): void
    {
        $this->assertSame('company:1:settings', (new CompanyContext)->cacheKey('settings'));
    }

    public function test_storage_path_is_namespaced_by_company(): void
    {
        $this->assertSame('companies/1/invoices/42.pdf', (new CompanyContext)->storagePath('invoices/42.pdf'));
    }
}
