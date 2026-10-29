<?php

namespace Tests\Unit\Modules\Sales;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The unguarded preview (`PreviewQuotation::forRevision`) carries internal figures and runs no authority check of its own. It may be called from exactly one place — the
 * readiness assessor, which only ever runs behind the guarded Actions — so nothing else can reach it without the exact duty.
 */
class PreviewQuotationInternalUseTest extends TestCase
{
    public function test_the_unguarded_preview_has_one_caller_the_readiness_assessor(): void
    {
        $callers = [];
        foreach ((new Finder)->files()->in(dirname(__DIR__, 4).'/app')->name('*.php') as $file) {
            if (preg_match('/(?<!\$this)->forRevision\(|::forRevision\(/', file_get_contents($file->getPathname()))) {
                $callers[] = str_replace(dirname(__DIR__, 4).'/app/', '', $file->getPathname());
            }
        }

        $this->assertSame(['Modules/Sales/Support/Quotation/Issue/QuotationIssueAssessor.php'], $callers);
    }

    public function test_the_assessor_is_reached_only_through_the_guarded_actions(): void
    {
        $callers = [];
        foreach ((new Finder)->files()->in(dirname(__DIR__, 4).'/app')->name('*.php') as $file) {
            if (preg_match('/QuotationIssueAssessor\b/', file_get_contents($file->getPathname())) && ! str_ends_with($file->getFilename(), 'QuotationIssueAssessor.php')) {
                $callers[] = $file->getFilename();
            }
        }
        sort($callers);

        $this->assertSame(['AssessQuotationIssueReadiness.php', 'IssueQuotation.php'], $callers);
    }
}
