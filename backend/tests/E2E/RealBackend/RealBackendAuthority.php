<?php

declare(strict_types=1);

namespace Tests\E2E\RealBackend;

use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use App\Support\JobSource\JobSourceFamilies;
use Illuminate\Contracts\Foundation\Application;

/**
 * TEST-ONLY authority for the isolated real-backend browser harness. It lives under tests/ (autoload-dev: never installed in a production image) and is applied ONLY by
 * tests/E2E/RealBackend/serve.php, which refuses to start unless the process is the dedicated harness (ERP_E2E_BACKEND=1, APP_ENV=testing and a private `*_e2e` database).
 *
 * It changes nothing in the shipped manifests, `SalesDevPreview` or any production code path: it rebinds the two manifests, the company resolver and the source-family registry in THIS process's container, which ends with the
 * process. It grants nobody anything either: every holder is created explicitly by the world builder in the throwaway database. No environment variable in production code
 * switches any of this on, and an architecture test pins that.
 */
final class RealBackendAuthority
{
    /** @var list<string> */
    public const SALES = [
        P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD, P::CUSTOMER_DECISION_ACCEPT,
        P::HANDOVER_PREPARE, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE,
    ];

    /** @var list<string> */
    public const JOBS = ['job.receive', 'job.view', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.close'];

    public static function apply(Application $app): void
    {
        $app->instance(CompanyContext::class, new TokenCompanyContext);
        $app->instance(JobSourceFamilies::class, NoBindingFamilies::registry());
        $app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped()->withActivated(self::SALES));
        $app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(
            fn (string $name): JobDuty => new JobDuty($name, JobDutyStatus::Active, 'op', 'projection', 'gate'),
            self::JOBS,
        )));
    }

    /** The environment the harness server (and only it) accepts; a failure names every unmet condition and starts nothing. */
    public static function refusal(): ?string
    {
        $problems = [];
        if (getenv('ERP_E2E_BACKEND') !== '1') {
            $problems[] = 'ERP_E2E_BACKEND=1 is not set';
        }
        if (getenv('APP_ENV') !== 'testing') {
            $problems[] = 'APP_ENV is not testing';
        }
        $database = (string) getenv('TEST_DB_DATABASE');
        if (! preg_match('/^erp_testing_[a-z0-9_]*e2e$/', $database)) {
            $problems[] = "the database [$database] is not a private erp_testing_*e2e database";
        }

        return $problems === [] ? null : 'The real-backend test harness refuses to start: '.implode('; ', $problems).'.';
    }
}
