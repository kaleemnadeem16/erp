<?php

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\Review\ApprovalReviewRegistry;
use App\Support\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['approval_policy_steps', 'approval_request_steps'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->string('review_profile', 16)->default('COMMERCIAL'));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_review_profile_check CHECK (review_profile IN ('COMMERCIAL', 'OPERATIONAL'))");
        }
        DB::statement("ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_review_spend_check CHECK (review_profile <> 'OPERATIONAL' OR NOT use_authority_limit)");
        $this->grantTechnicalReview();
        $this->preparePendingRequests();
    }

    public function grantTechnicalReview(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'approvals.document.technical-review', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $new = DB::table('permissions')->where('name', 'approvals.document.technical-review')->where('guard_name', 'web')->value('id');
        $prior = DB::table('permissions')->where('name', 'purchasing.purchase-order.approve')->where('guard_name', 'web')->value('id');
        if ($prior !== null) {
            foreach (DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->where('role_has_permissions.permission_id', $prior)->where('roles.guard_name', 'web')->pluck('roles.id') as $role) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $new]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $prior)->get() as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $new, 'model_type' => $grant->model_type, 'model_id' => $grant->model_id, 'team_id' => $grant->team_id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function preparePendingRequests(): void
    {
        $previous = app(CompanyContext::class);
        try {
            foreach (DB::table('approval_requests')->where('subject_type', 'PURCHASE_ORDER')->where('status', 'PENDING')->distinct()->pluck('company_id') as $companyId) {
                app()->instance(CompanyContext::class, new class((int) $companyId) extends CompanyContext
                {
                    public function __construct(private readonly int $companyId) {}

                    public function id(): int
                    {
                        return $this->companyId;
                    }
                });
                $ids = ApprovalRequest::query()->where('subject_type', 'PURCHASE_ORDER')->where('status', 'PENDING')->pluck('id');
                foreach ($ids as $id) {
                    DB::transaction(function () use ($id): void {
                        $request = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                        if (! $request->isOpen() || isset($request->facts['document_review'])) {
                            return;
                        }
                        $reviews = app(ApprovalReviewRegistry::class);
                        $snapshot = $reviews->capture($request->subject_type, $request->subject_id);
                        $routing = app(ApprovalSubjectRegistry::class)->get($request->subject_type)->routing($request->subject_id);
                        if ($request->facts_hash === $routing->hash()) {
                            $request->update(['facts' => $request->facts + ['document_review' => $snapshot]]);
                        }
                    });
                }
            }
        } finally {
            app()->instance(CompanyContext::class, $previous);
        }
    }

    public function down(): void
    {
        // Stored request/decision snapshots are historical evidence and survive rollback.
        foreach (['approval_request_steps', 'approval_policy_steps'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('review_profile'));
        }
    }
};
