<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityEvaluationStore;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationOperation;

final class EvaluateQuotationDiscountAuthority
{
    public function __construct(private readonly QuotationOperation $operations) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, string $key): array
    {
        $outcome = $this->operations->run(SalesActorContext::forUser($actor, 'quotation.evaluate_discount_authority'), 'quotation.evaluate_discount_authority', $key,
            ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration],
            function () use ($actor, $revisionId): QuotationRevision {
                app(AuthorityFence::class)->lock();

                return app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true);
            },
            function (QuotationRevision $revision) use ($actor, $expectedGeneration): array {
                if (! $revision->isDraft() || $revision->input_generation !== $expectedGeneration) {
                    throw AuthorityRefusal::make('AUTHORITY_STALE', 'Reload the editable quotation before evaluating its authority.');
                }
                $mode = app(DiscountConfiguration::class)->mode();
                if ($mode === 'OFF') {
                    throw AuthorityRefusal::make('AUTHORITY_DISABLED', 'Discount authority evaluation is disabled for this company.');
                }
                $assessment = app(AssessDiscountAuthority::class)->assess($revision);
                $id = app(AuthorityEvaluationStore::class)->record($actor, $revision, $mode, $assessment);

                return ['family_id' => $revision->family_id, 'revision_id' => $revision->id, 'evaluation_id' => $id, 'result' => $assessment['result'], 'reasons' => $assessment['reasons']];
            });

        $result = $outcome['result'];
        ksort($result);

        return $result;
    }
}
