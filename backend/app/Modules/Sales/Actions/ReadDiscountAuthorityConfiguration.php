<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReadDiscountAuthorityConfiguration
{
    /** Explicit scalar projections: no evaluation snapshots, receipts or arbitrary model attributes. */
    public const COLLECTIONS = [
        'references' => ['sales_price_reference_versions', ['id', 'code', 'version_no', 'source_kind', 'source_key', 'source_version', 'family_id', 'customer_id', 'item_id', 'manual_measure', 'uom_id', 'currency_id', 'unit_price', 'effective_from', 'effective_to', 'priority', 'proposed_by', 'reason']],
        'policies' => ['sales_discount_policy_versions', ['id', 'code', 'version_no', 'priority', 'base_currency_id', 'allow_higher_approver', 'operation', 'customer_id', 'unit_id', 'contains_item_id', 'enforcement', 'effective_from', 'effective_to', 'expiry_calendar_days', 'created_by', 'reason']],
        'bands' => ['sales_discount_bands', ['id', 'policy_id', 'rank', 'label', 'exception_only', 'line_percent', 'line_base_amount', 'document_percent', 'document_base_amount', 'input_percent', 'input_base_amount']],
        'assignments' => ['sales_discount_assignments', ['id', 'user_id', 'band_id', 'purpose', 'role_id', 'unit_id', 'effective_from', 'effective_to', 'assigned_by', 'reason']],
        'reference-approvals' => ['sales_price_reference_activations', ['id', 'reference_id', 'approved_by', 'reason', 'recorded_at']],
        'policy-activations' => ['sales_discount_policy_activations', ['id', 'policy_id', 'actor_id', 'reason', 'recorded_at']],
        'revocations' => ['sales_discount_revocations', ['id', 'assignment_id', 'actor_id', 'reason', 'recorded_at']],
        'mode-events' => ['sales_discount_mode_events', ['id', 'mode', 'actor_id', 'reason', 'recorded_at']],
    ];

    /** @return array{generation:int, mode:string, page:int, per_page:int, total:int, records:list<array<string, string|int|bool|null>>} */
    public function __invoke(User $actor, string $collection, ?int $id = null, int $page = 1, int $perPage = 25): array
    {
        return DB::transaction(function () use ($actor, $collection, $id, $page, $perPage): array {
            app(CompanyAccessFence::class)->lockAll();
            $fresh = ActiveActor::require($actor, 'read discount authority configuration');
            if ($fresh->must_change_password) {
                throw new AuthorizationException('Complete the password change before reading authority configuration.');
            }
            $duty = in_array($collection, ['references', 'reference-approvals'], true) && ! SalesAccess::may($fresh, PermissionVocabulary::FINANCIAL_CONFIGURE)
                ? PermissionVocabulary::PRICE_REFERENCE_APPROVE : PermissionVocabulary::FINANCIAL_CONFIGURE;
            SalesAccess::require($fresh, $duty);
            $generation = app(AuthorityFence::class)->lock();
            [$table, $columns] = self::COLLECTIONS[$collection] ?? throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Choose a supported configuration collection.');
            $query = DB::table($table)->where('company_id', app(CompanyContext::class)->id());
            if (in_array($collection, ['references', 'reference-approvals'], true)) {
                $references = DB::table('sales_price_reference_versions')->where('company_id', app(CompanyContext::class)->id());
                try {
                    $families = app(QuotationAccess::class)->queryFor($fresh, [PermissionVocabulary::QUOTATION_VIEW])->select('quotation_families.id');
                    $references->where(fn ($q) => $q->whereNull('family_id')->orWhereIn('family_id', $families));
                } catch (AuthorizationException) {
                    $references->whereNull('family_id');
                }
                $query = $collection === 'references' ? $references : $query->whereIn('reference_id', $references->select('id'));
            }
            if ($id !== null) {
                $query->where('id', $id);
            }
            $perPage = max(1, min(100, $perPage));
            $page = max(1, $page);
            $total = $query->count();
            if ($id !== null && $total === 0) {
                throw (new ModelNotFoundException)->setModel(Model::class, [$id]);
            }
            $records = $query->select($columns)->orderByDesc('id')->forPage($page, $perPage)->get()->map(function ($row): array {
                $record = (array) $row;
                if (isset($record['recorded_at'])) {
                    $record['recorded_at'] = Carbon::parse($record['recorded_at'])->utc()->toIso8601String();
                }

                return $record;
            })->all();

            return ['generation' => $generation, 'mode' => app(DiscountConfiguration::class)->mode(), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'records' => $records];
        });
    }
}
