<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationNetControl;
use App\Modules\Sales\Support\Financial\Controls\ControlBasis;
use App\Modules\Sales\Support\Financial\Controls\ControlMode;
use App\Modules\Sales\Support\Financial\Controls\ControlRefusal;
use App\Modules\Sales\Support\Financial\Controls\NetControl;
use App\Modules\Sales\Support\Financial\Controls\NetControlKind;
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the next version of the company's final-net control set (D3): a whole, immutable set that governs every quotation issued after it. Guarded by
 * `sales.financial.configure` (STAGED) and a currently active member; the controls are the company's rules, never a seller input. Only the kinds the evaluator supports are
 * accepted (`FLOOR`, `MIN_MARGIN_AMOUNT`, `MIN_MARGIN_PERCENT`), each validated by `NetControl` itself, and the set is pinned to the rounding policy in force today: a later change of
 * that policy makes every control UNEVALUABLE until a set is published against the new one, never a false pass. An empty set is refused. No route calls it.
 */
final class PublishQuotationControlSet
{
    private const KINDS = [NetControlKind::Floor, NetControlKind::MinMarginAmount, NetControlKind::MinMarginPercent];

    public function __construct(private readonly CompanyContext $company, private readonly ResolveRoundingPolicy $rounding) {}

    /** @param  list<array<string, mixed>>  $controls ref, kind, mode, limit and, per kind, currency_id and basis */
    public function __invoke(User $actor, array $controls, string $reason): QuotationControlSet
    {
        ActiveActor::require($actor, 'publish quotation controls');
        SalesAccess::require($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, 'publish quotation controls');
        $reason = trim($reason);
        if ($controls === [] || count($controls) > 200 || $reason === '' || mb_strlen($reason) > 1000) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A control set needs one to 200 controls and a reason of up to 1000 characters.', 'controls');
        }
        $built = [];
        foreach ($controls as $row) {
            $kind = NetControlKind::tryFrom((string) ($row['kind'] ?? ''));
            $mode = ControlMode::tryFrom((string) ($row['mode'] ?? ''));
            $basis = ControlBasis::tryFrom((string) ($row['basis'] ?? ControlBasis::Line->value));
            if ($kind === null || ! in_array($kind, self::KINDS, true) || $mode === null || $basis === null) {
                throw ControlRefusal::make(ControlRefusal::CONTROL_KIND_NOT_SUPPORTED, 'That kind of control cannot be published yet.', 'controls');
            }
            $currencyId = isset($row['currency_id']) ? (int) $row['currency_id'] : null;
            if ($currencyId !== null && ! Currency::query()->whereKey($currencyId)->exists()) {
                throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A control limit names a currency that does not exist.', 'controls');
            }
            // NetControl is the validation: opaque id, a plain non-negative limit, percent without a currency, amount with one.
            $built[] = new NetControl((string) ($row['ref'] ?? ''), 1, $kind, $mode, (string) ($row['limit'] ?? ''), $currencyId, $basis);
        }
        if (count(array_unique(array_map(static fn (NetControl $c): string => $c->id, $built))) !== count($built)) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'Controls must have distinct references.', 'controls');
        }

        return DB::transaction(function () use ($actor, $built, $reason): QuotationControlSet {
            // The company row is the fence every Sales financial policy writer takes, so version numbers cannot race.
            $company = Company::query()->whereKey($this->company->id())->lockForUpdate()->firstOrFail();
            $today = now($company->timezone)->format('Y-m-d');
            $set = QuotationControlSet::create([
                'version_no' => (int) QuotationControlSet::query()->max('version_no') + 1, 'rounding_policy_fingerprint' => $this->rounding->__invoke($today)->fingerprint(),
                'reason' => $reason, 'published_by' => $actor->id,
            ]);
            foreach ($built as $control) {
                QuotationNetControl::create([
                    'control_set_id' => $set->id, 'control_ref' => $control->id, 'control_version' => $control->version, 'kind' => $control->kind->value, 'mode' => $control->mode->value,
                    'limit_value' => (string) $control->value, 'currency_id' => $control->currencyId, 'basis' => $control->basis->value,
                ]);
            }

            return $set->refresh();
        });
    }
}
