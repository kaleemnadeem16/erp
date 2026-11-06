<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Support\CompanyContext;
use App\Support\Demo\QuotationDemoBoundary;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0155: the single, create-once first-holder bootstrap of the synthetic demo access operator. Ordinary SaveRole and SyncMemberAccess correctly refuse to hand out a duty the
 * grantor does not hold, and nobody holds the staged assignment/share duties yet, so this Action - and only this Action - gives the operator exactly the approved set
 * (`QuotationDemoBoundary::DUTIES`: view, prepare, assign, delegate, revoke) as direct grants. Nothing else.
 *
 * - Exact identity and company: the operator's e-mail is `QuotationDemoBoundary::OPERATOR_EMAIL`, the company is designated demo Company 1 and the current one. The row is re-read under the
 *   company fence, so a hydrated model of another company or of a different identity is refused.
 * - Environment: refused in production outright; otherwise local/testing or `DEMO_MODE=true`. DEMO_MODE is not a production bypass.
 * - No activation: the duties must already be ACTIVE in the container's manifest (the local preview). The Action does not register or activate anything.
 * - Create-once: the grants and their provenance are written together with an append-only receipt. A rerun returns without writing - it never repairs or replenishes a grant that was revoked
 *   later - and a rerun that asks for a different grant set than the receipt fixed is refused. Issue and repair can never be part of the set: any set other than the approved one is refused.
 * - Nothing here is a route, a screen or an ordinary-administration shortcut.
 */
final class BootstrapDemoAccessOperator
{
    /** @return bool true when this call performed the bootstrap, false when it had already happened (and nothing was written) */
    public function __invoke(User $operator, ?array $grants = null, string $provenance = 'Approved synthetic demo operator bootstrap (ADR 0155)'): bool
    {
        $grants ??= QuotationDemoBoundary::DUTIES;
        $approved = QuotationDemoBoundary::DUTIES;
        sort($approved);
        $requested = array_values(array_unique($grants));
        sort($requested);
        if ($requested !== $approved) {
            throw new DomainInvariantException('The demo access operator bootstrap grants exactly the approved quotation duties and nothing else.', 'grants');
        }
        if (app()->environment('production')) {
            throw new DomainInvariantException('Quotation demo access is unavailable in this environment or company.', 'company_id');
        }
        QuotationDemoBoundary::assertCompany((int) $operator->company_id);
        $manifest = app(SalesDutyManifest::class);
        foreach ($approved as $duty) {
            if (! $manifest->isActive($duty)) {
                throw new DomainInvariantException("The duty {$duty} is not active here; this Action never activates a duty (local preview: APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true).", 'grants');
            }
        }

        return DB::transaction(function () use ($operator, $approved, $provenance): bool {
            app(CompanyAccessFence::class)->lockAll();
            $company = app(CompanyContext::class)->id();
            $operator = User::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();
            if ((int) $operator->company_id !== $company || $operator->email !== QuotationDemoBoundary::OPERATOR_EMAIL) {
                throw new DomainInvariantException('Only the approved synthetic demo access operator can be bootstrapped.', 'email');
            }
            if ($operator->status !== MemberStatus::Active) {
                throw new DomainInvariantException('The synthetic demo access operator must be an active member.', 'email');
            }
            $receipt = DB::table('demo_access_operator_bootstraps')->where('company_id', $company)->where('user_id', $operator->id)->first();
            if ($receipt !== null) {
                $fixed = json_decode((string) $receipt->grants, true);
                sort($fixed);
                if ($fixed !== $approved) {
                    throw new DomainInvariantException('The recorded bootstrap grants differ from the approved set; they are never altered.', 'grants');
                }

                return false;
            }
            if (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $approved)->count() !== count($approved)) {
                throw new DomainInvariantException('The approved duties have no permission rows yet; register the active vocabulary first (permission:register). This Action registers nothing.', 'grants');
            }
            $operator->givePermissionTo($approved);
            DB::table('demo_access_operator_bootstraps')->insert([
                'company_id' => $company, 'user_id' => $operator->id, 'email' => $operator->email, 'grants' => json_encode($approved), 'provenance' => $provenance,
            ]);
            $operator->forceFill(['access_updated_at' => now()])->save();
            activity()->performedOn($operator)->withProperties(['grants' => $approved, 'decision' => 'ADR 0155', 'provenance' => $provenance])->log('demo access operator bootstrapped');

            return true;
        });
    }
}
