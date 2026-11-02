<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RecordManualQuotationDelivery;
use App\Modules\Sales\Actions\RequestQuotationDocument;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Demo data for the quotation document feature: one issued quotation with its generated official PDF and a recorded manual delivery, created once through the real Actions
 * (every key is fixed, so a rerun adds nothing). It runs ONLY on a developer's machine with the local quotation preview switched on (SALES_DEV_QUOTATION_DUTIES) and the
 * named demo login holding the preview duties and the issue duty; otherwise it says why it skipped and writes nothing. It never grants anything and never fakes tax or
 * control evidence: if the real issue path refuses (for example no tax context is configured yet), the refusal is shown and nothing is half-made.
 */
final class QuotationDocumentsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        if (! SalesDevPreview::enabled()) {
            $this->command->getOutput()->writeln('  Quotation documents demo skipped: set SALES_DEV_QUOTATION_DUTIES=true in a local environment (and run sales:dev-grant-quotation for the demo login) to build it.');

            return;
        }
        $company = app(CompanyContext::class);
        $maker = User::query()->where('company_id', $company->id())->orderBy('id')->first();
        $customer = Party::query()->orderBy('id')->first();
        if ($maker === null || $customer === null) {
            $this->command->getOutput()->writeln('  Quotation documents demo skipped: no login or customer exists yet.');

            return;
        }
        if (! $maker->can(PermissionVocabulary::QUOTATION_ISSUE)) {
            $this->command->getOutput()->writeln('  Quotation documents demo skipped: the first login does not hold sales.quotation.issue (issuing is not part of the local preview).');

            return;
        }

        try {
            DB::transaction(function () use ($maker, $customer): void {
                $created = app(CreateQuotationDraft::class)($maker, 'demo-doc-draft', ['recipient_party_id' => $customer->id, 'pricing_date' => now()->format('Y-m-d'),
                    'tax_estimate_date' => now()->format('Y-m-d'), 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
                $revision = QuotationRevision::query()->findOrFail($created->revisionId);
                if ($revision->isDraft()) {
                    app(UpdateQuotationDraft::class)($maker, $revision->id, $revision->input_generation, 'demo-doc-lines', ['add_lines' => [
                        ['source_kind' => 'MANUAL', 'description' => 'Site survey and measurement', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '15000.0000'],
                        ['source_kind' => 'MANUAL', 'description' => 'Two-coat interior painting, living areas', 'manual_measure' => 'room', 'offered_quantity' => '4', 'unit_price' => '42000.0000'],
                        ['source_kind' => 'MANUAL', 'description' => 'Optional: exterior wall sealing', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '30000.0000', 'membership' => 'OPTIONAL'],
                    ]]);
                    $revision->refresh();
                    app(IssueQuotation::class)($maker, $revision->id, $revision->input_generation, 'demo-doc-issue');
                }
                app(RequestQuotationDocument::class)($maker, $revision->id, 'demo-doc-generate');
                app(RecordManualQuotationDelivery::class)($maker, $revision->id, ['channel' => 'HAND', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Demo customer front desk',
                    'note' => 'Printed copy handed over (demo)'], 'demo-doc-delivery');
            });
            $this->command->getOutput()->writeln('  Quotation documents demo: one issued quotation with its official PDF and a manual delivery record.');
        } catch (Throwable $e) {
            $this->command->getOutput()->writeln('  Quotation documents demo not built: '.$e->getMessage());
        }
    }
}
