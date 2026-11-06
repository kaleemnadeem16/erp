<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Modules\Sales\Support\Authority\DutyClass;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/** Recursive companion to the original root-Action ratchet; access workflows cannot hide in a subdirectory. */
class QuotationShareWriterCoverageTest extends TestCase
{
    public function test_all_governed_quotation_writers_including_access_workflows_are_registered(): void
    {
        $root = app_path('Modules/Sales/Actions');
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'QuotationOperation')) {
                $found[] = substr($file->getPathname(), strlen($root) + 1, -4);
            }
        }
        $this->assertEqualsCanonicalizing([
            'CreateQuotationDraft', 'UpdateQuotationDraft', 'CreateQuotationRevision', 'IssueQuotation', 'IssueQuotationReplacement',
            'WithdrawQuotationOffer', 'CancelQuotationDraft', 'ReassignQuotationOwner', 'ChangeQuotationAccessUnit', 'CaptureQuotationAccessBaseline',
            'Access/GrantQuotationAccess', 'Access/RevokeQuotationAccess',
            // Approvals integration (ADR 0158): the approval Actions take the same governed quotation operation.
            'RequestQuotationApproval', 'DecideQuotationApproval', 'WithdrawQuotationApproval', 'BindQuotationAuthorityReferences',
        ], $found);
        foreach (['GrantQuotationAccess', 'RevokeQuotationAccess'] as $action) {
            $source = (string) file_get_contents($root.'/Access/'.$action.'.php');
            $this->assertStringContainsString('QuotationSharing::requireAuthority(', $source);
            $this->assertStringContainsString('QuotationSharing::lockFamily(', $source);
            $this->assertStringContainsString('$this->operations->run(', $source);
            $this->assertStringContainsString('QuotationOwnership::requireGenerations(', $source);
        }
    }

    public function test_new_access_duties_remain_staged_sensitive_and_outside_automatic_registration(): void
    {
        $manifest = SalesDutyManifest::shipped();
        $registry = app(PermissionRegistry::class);
        $names = [PermissionVocabulary::QUOTATION_ACCESS_DELEGATE, PermissionVocabulary::QUOTATION_ACCESS_REVOKE];
        foreach ($names as $name) {
            $duty = $manifest->find($name);
            $this->assertSame(DutyStatus::Staged, $duty->status);
            $this->assertSame(DutyClass::Sensitive, $duty->class);
            $this->assertFalse($manifest->isActive($name));
            $this->assertNotContains($name, $registry->all());
            $this->assertNotContains($name, PermissionVocabulary::all());
        }
        $fixture = $manifest->withActivated($names);
        $excluded = array_merge(...array_values($fixture->automaticGrantExclusions()));
        foreach ($names as $name) {
            $this->assertContains($name, $excluded, 'Even explicit fixture activation cannot make a sensitive duty automatically grantable.');
        }
    }
}
