<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\DiscountAuthority\GovernedReferences;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use Illuminate\Support\Facades\DB;

/** Captures references only. It neither grants routine clearance nor changes a draft price. No HTTP caller in stage 1. */
final class BindQuotationAuthorityReferences
{
    public function __construct(private readonly QuotationOperation $operations) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, string $key): array
    {
        $outcome = $this->operations->run(SalesActorContext::forUser($actor, 'quotation.bind_authority_references'), 'quotation.bind_authority_references', $key,
            ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration],
            function () use ($actor, $revisionId): QuotationRevision {
                app(AuthorityFence::class)->lock();

                return app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true);
            },
            function (QuotationRevision $revision) use ($actor, $expectedGeneration): array {
                if (! $revision->isDraft() || $revision->input_generation !== $expectedGeneration) {
                    throw AuthorityRefusal::make('AUTHORITY_STALE', 'Reload the editable quotation before binding its references.');
                }
                $ids = [];
                foreach ($revision->lines()->orderBy('id')->get() as $line) {
                    $reference = app(GovernedReferences::class)->resolve($revision, $line);
                    $economicKey = GovernedReferences::economicKey($line);
                    DB::table('sales_quotation_reference_bindings')->insertOrIgnore(['company_id' => $revision->company_id, 'family_id' => $revision->family_id,
                        'economic_key' => $economicKey, 'reference_id' => $reference->id, 'bound_by' => $actor->id]);
                    $ids[(string) $line->id] = $reference->id;
                }

                return ['family_id' => $revision->family_id, 'revision_id' => $revision->id, 'references' => $ids];
            });

        return $outcome['result'];
    }
}
