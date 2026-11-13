<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/** Inventory ratchet, supplemented by the named behaviour suites; source presence alone is not a security proof. */
class QuotationAccessSurfaceCoverageTest extends TestCase
{
    /** route => [Action, policy path, duty, projection, behaviour suite] */
    private const SURFACES = [
        'GET api/v1/quotations/{revision}/acceptance-preview' => ['PreviewCustomerAcceptance', 'lock', 'accept + decision.view + quotation.view', 'exact issued terms, opaque review', 'CustomerAcceptanceApiTest'],
        'POST api/v1/quotations/{revision}/acceptances' => ['RecordCustomerAcceptance', 'lock', 'accept + decision.view + quotation.view', 'acceptance identity receipt', 'CustomerAcceptanceApiTest'],
        'GET api/v1/customer-acceptances/{acceptance}/handover-preview' => ['PreviewHandover', 'lock', 'handover.prepare + acceptance view + quotation view', 'permitted operational preview, pending readiness', 'HandoverApiTest'],
        'POST api/v1/customer-acceptances/{acceptance}/handover-plans' => ['PrepareHandoverPlan', 'lock', 'handover.prepare + acceptance view + quotation view', 'plan receipt identity', 'HandoverApiTest'],
        'GET api/v1/handover-plans/{plan}' => ['ReadHandoverPlan', 'lock', 'acceptance view + quotation view', 'immutable operational plan without token or commercial terms', 'HandoverApiTest'],
        'GET api/v1/quotations' => ['ListQuotations', 'queryFor', 'view', 'safe identities', 'QuotationListApiTest'],
        'POST api/v1/quotations' => ['CreateQuotationDraft', 'queryFor', 'prepare', 'receipt identity', 'QuotationDraftWriteApiTest'],
        'GET api/v1/quotations/{revision}' => ['ReadQuotationDraft', 'requireRevision', 'view', 'safe header', 'QuotationDraftReviewApiTest'],
        'GET api/v1/quotations/{revision}/editable' => ['ReadQuotationDraftForEdit', 'requireRevision', 'view + prepare', 'editable commercial operands', 'QuotationDraftEditableApiTest'],
        'GET api/v1/quotations/{revision}/tax-declarations' => ['ReadQuotationTaxDeclarations', 'requireRevision', 'view + prepare', 'seller tax declaration versions', 'QuotationTaxDeclarationTest'],
        'PATCH api/v1/quotations/{revision}' => ['UpdateQuotationDraft', 'requireRevision', 'view + prepare', 'receipt identity', 'QuotationDraftWriteApiTest'],
        'GET api/v1/quotations/{revision}/tax-declarations' => ['ReadQuotationTaxDeclarations', 'requireRevision', 'view + prepare', 'seller declaration versions; no Tax rates or amounts', 'QuotationTaxDeclarationTest'],
        'GET api/v1/quotations/{revision}/preview' => ['PreviewQuotation', 'requireRevision', 'view + prepare', 'commercial preview', 'QuotationDraftReviewApiTest'],
        'GET api/v1/quotations/{revision}/issue-readiness' => ['AssessQuotationIssueReadiness', 'requireRevision', 'view + prepare', 'readiness codes', 'QuotationIssueApiTest'],
        'POST api/v1/quotations/{revision}/issue' => ['IssueQuotation', 'requireRevision', 'view + issue', 'receipt identity', 'QuotationIssueApiTest'],
        'GET api/v1/quotations/{revision}/issued' => ['ReadIssuedQuotation', 'requireRevision', 'view; totals separately prepare/issue', 'safe issued header', 'QuotationIssueApiTest'],
        // Approvals integration (ADR 0158): the quotation-family policy decides per revision; the engine's own authority is asked as well inside the decision.
        'GET api/v1/quotations/{revision}/discount-authority' => ['ReadQuotationDiscountAuthority', 'requireRevision', 'view + independent authority evidence', 'protected evaluated concession summary', 'DiscountAuthorityApiTest'],
        'GET api/v1/quotations/{revision}/discount-authority/history' => ['ReadQuotationDiscountAuthority', 'requireRevision', 'view + independent authority evidence', 'paged protected evaluation summaries', 'DiscountAuthorityApiTest'],
        'POST api/v1/quotations/{revision}/authority-references' => ['BindQuotationAuthorityReferences', 'requireRevision', 'view + prepare', 'binding count only', 'DiscountAuthorityApiTest'],
        'GET api/v1/quotations/{revision}/approval' => ['ReadQuotationApproval', 'requireRevision', 'view + (prepare or approve)', 'approval standing; names, codes, written reasons; no amounts or limits', 'QuotationApprovalApiTest'],
        'POST api/v1/quotations/{revision}/approval' => ['RequestQuotationApproval', 'requireRevision', 'view + prepare', 'receipt identity', 'QuotationApprovalApiTest'],
        'POST api/v1/quotations/{revision}/approval/decision' => ['DecideQuotationApproval', 'requireRevision', 'view + approve', 'receipt identity', 'QuotationApprovalApiTest'],
        'POST api/v1/quotations/{revision}/approval/withdrawal' => ['WithdrawQuotationApproval', 'requireRevision', 'view + prepare', 'receipt identity', 'QuotationApprovalApiTest'],
        'GET api/v1/quotations/{revision}/document' => ['ReadQuotationDocumentStatus', 'locator', 'document.view + quotation.view', 'safe document status', 'QuotationDocumentApiTest'],
        'POST api/v1/quotations/{revision}/document' => ['RequestQuotationDocument', 'locator', 'document.generate + quotation.view', 'safe document status', 'QuotationDocumentApiTest'],
        'GET api/v1/quotations/{revision}/document/download' => ['DownloadQuotationDocument', 'locator', 'document.download + quotation.view', 'authorised PDF bytes', 'QuotationDocumentApiTest'],
        'GET api/v1/quotations/{revision}/document/history' => ['ReadQuotationDocumentHistory', 'locator', 'document.view + quotation.view', 'safe history', 'QuotationDocumentApiTest'],
        'POST api/v1/quotations/{revision}/document/check' => ['RepairQuotationDocument', 'locator', 'document.repair + quotation.view', 'outcome code', 'QuotationDocumentApiTest'],
        'GET api/v1/quotations/{revision}/deliveries' => ['ReadQuotationDocumentDeliveries', 'locator', 'delivery.view + quotation.view', 'safe delivery evidence', 'QuotationDocumentApiTest'],
        'POST api/v1/quotations/{revision}/deliveries' => ['RecordManualQuotationDelivery', 'locator', 'delivery.record + quotation.view', 'safe delivery evidence', 'QuotationDocumentApiTest'],
        'POST api/v1/quotations/{revision}/deliveries/{delivery}/correction' => ['CorrectManualQuotationDelivery', 'locator', 'delivery.record + quotation.view', 'safe delivery evidence', 'QuotationDeliveryCorrectionTest'],
    ];

    public function test_every_quotation_route_has_a_request_action_policy_projection_and_behaviour_inventory(): void
    {
        $found = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! preg_match('#^api/v1/(quotations(?:/|$)|customer-acceptances/\{acceptance\}/handover-|handover-plans(?:/|$))#', $route->uri())) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $key = $method.' '.$route->uri();
                $found[] = $key;
                $this->assertArrayHasKey($key, self::SURFACES, "{$key} needs an exact-duty, projection and test review");
                [$action, $policy, $duty, $projection, $suite] = self::SURFACES[$key];
                [$controller, $handler] = explode('@', $route->getActionName());
                $parameters = (new ReflectionMethod($controller, $handler))->getParameters();
                $types = array_map(fn ($parameter): string => ($type = $parameter->getType()) instanceof ReflectionNamedType ? $type->getName() : '', $parameters);
                $this->assertTrue((bool) array_filter($types, fn (string $type): bool => is_subclass_of($type, FormRequest::class)), "{$key} needs its Request guard");
                $this->assertContains('App\\Modules\\Sales\\Actions\\'.$action, $types);
                $source = (string) file_get_contents(app_path('Modules/Sales/Actions/'.$action.'.php'));
                $this->assertStringContainsString($policy === 'locator' ? '->authorise(' : '->'.$policy.'(', $source);
                $this->assertNotSame('', $duty);
                $this->assertNotSame('', $projection);
                $this->assertFileExists(base_path('tests/Feature/Modules/Sales/'.$suite.'.php'));
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(self::SURFACES), $found);
        $locator = (string) file_get_contents(app_path('Modules/Sales/Support/Documents/QuotationDocumentLocator.php'));
        $this->assertStringContainsString('QuotationAccess::class)->requireRevision(', $locator);
    }

    public function test_internal_family_writers_keep_the_exclusive_fence_before_subject_and_receipt(): void
    {
        $registered = ['CreateQuotationDraft', 'UpdateQuotationDraft', 'AdoptQuotationMaterialPlan', 'CreateQuotationRevision', 'IssueQuotation', 'IssueQuotationReplacement',
            'WithdrawQuotationOffer', 'CancelQuotationDraft', 'ReassignQuotationOwner', 'ChangeQuotationAccessUnit', 'CaptureQuotationAccessBaseline',
            'RequestQuotationApproval', 'DecideQuotationApproval', 'WithdrawQuotationApproval', 'BindQuotationAuthorityReferences', 'EvaluateQuotationDiscountAuthority'];
        $found = [];
        foreach (glob(app_path('Modules/Sales/Actions/*.php')) as $file) {
            if (str_contains((string) file_get_contents($file), 'QuotationOperation')) {
                $found[] = basename($file, '.php');
            }
        }
        $this->assertEqualsCanonicalizing($registered, $found, 'every governed operation needs a writer-inventory review');
        foreach ($registered as $action) {
            $source = (string) file_get_contents(app_path('Modules/Sales/Actions/'.$action.'.php'));
            $this->assertStringContainsString('$this->operations->run(', $source, $action);
        }
        $operation = (string) file_get_contents(app_path('Modules/Sales/Support/Quotation/QuotationOperation.php'));
        $this->assertLessThan(strpos($operation, '$subject = $lockSubject()'), strpos($operation, '$this->accessFence->lockAll()'));
        $this->assertLessThan(strpos($operation, '$this->receipts->find('), strpos($operation, '$subject = $lockSubject()'));
    }
}
