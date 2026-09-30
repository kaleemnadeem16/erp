<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0070's shared approval engine, the storage half:
 *
 *  - approval_subject_settings — the company's approval mode per subject type (NONE / POLICY / REQUIRED).
 *  - approval_policies (+ conditions, steps) — versioned rule sets. Each row is one version of a named
 *    policy; the row id is the "policy version" a request snapshots. Same lifecycle as the overhead and
 *    material-variance policies: DRAFT → ACTIVE → SUPERSEDED, one ACTIVE per (subject, code).
 *  - approval_authorities — per-person amount ceilings held as data, never as permissions.
 *  - approval_delegations — dated cover: the delegate acts within the delegator's authority.
 *  - approval_requests (+ steps) — one routed approval, with the routing facts, their hash and the pinned
 *    currency evidence snapshotted at submission.
 *  - approval_decisions — append-only facts. `principal_id` is whose authority the decision used (the
 *    delegator when acting under a delegation), so one person can never fill two slots of a step.
 *
 * Every user reference is a composite (company_id, id) foreign key, like the other tenant-owned tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_subject_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('subject_type');
            $table->string('mode')->default('NONE');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'subject_type']);
        });

        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('subject_type');
            $table->string('code');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('name');
            $table->smallInteger('priority')->default(0);
            $table->string('status')->default('DRAFT');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'subject_type', 'code', 'version_no']);
        });

        Schema::create('approval_policy_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('policy_id');
            $table->string('fact');
            $table->string('operator');
            $table->jsonb('value');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('approval_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('policy_id');
            // Steps sharing a stage run in parallel; a stage opens when every step of the one before is approved.
            $table->unsignedSmallInteger('stage');
            $table->string('name');
            $table->unsignedSmallInteger('required_approvals')->default(1);
            // NULL falls back to the subject type's own approve capability.
            $table->string('permission')->nullable();
            $table->boolean('use_authority_limit')->default(false);
            // Maker/checker is a per-step choice; the safe default is that the submitter cannot satisfy it.
            $table->boolean('allow_submitter')->default(false);
            $table->unsignedInteger('expires_after_hours')->nullable();
            $table->string('on_expiry')->default('FLAG_OVERDUE');
            $table->string('escalation_permission')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('approval_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('user_id');
            $table->string('subject_type');
            $table->boolean('unlimited')->default(false);
            $table->decimal('ceiling_base_amount', 19, 4)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('changed_by');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('delegator_id');
            $table->unsignedBigInteger('delegate_id');
            // NULL covers every subject type the delegator may approve.
            $table->string('subject_type')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->text('reason');
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('policy_id');
            $table->string('status')->default('PENDING');
            $table->unsignedBigInteger('submitted_by');
            $table->timestampTz('submitted_at');
            $table->timestampTz('closed_at')->nullable();
            // The routing facts, including the pinned currency evidence, and a hash of them: an approval is
            // honoured only while the subject's current facts still hash to this.
            $table->jsonb('facts');
            $table->string('facts_hash', 64);
            $table->jsonb('summary');
            $table->text('closing_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('policy_step_id');
            $table->unsignedSmallInteger('stage');
            $table->string('name');
            $table->unsignedSmallInteger('required_approvals');
            $table->string('status')->default('WAITING');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('overdue_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('request_step_id');
            $table->string('decision');
            $table->unsignedBigInteger('decided_by');
            $table->unsignedBigInteger('principal_id');
            $table->unsignedBigInteger('delegation_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('authority_snapshot');
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            // One slot per person in a step, whether deciding personally or acting for someone else.
            $table->unique(['company_id', 'request_step_id', 'decided_by']);
            $table->unique(['company_id', 'request_step_id', 'principal_id']);
        });

        $this->constraints();
    }

    private function constraints(): void
    {
        DB::statement("ALTER TABLE approval_subject_settings ADD CONSTRAINT approval_subject_settings_mode_check CHECK (mode IN ('NONE', 'POLICY', 'REQUIRED'))");
        DB::statement('ALTER TABLE approval_subject_settings ADD CONSTRAINT approval_subject_settings_changed_by_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');

        DB::statement("ALTER TABLE approval_policies ADD CONSTRAINT approval_policies_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'SUPERSEDED'))");
        DB::statement('ALTER TABLE approval_policies ADD CONSTRAINT approval_policies_changed_by_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX approval_policies_one_active ON approval_policies (company_id, subject_type, code) WHERE status = 'ACTIVE'");

        DB::statement("ALTER TABLE approval_policy_conditions ADD CONSTRAINT approval_policy_conditions_operator_check CHECK (operator IN ('GTE', 'GT', 'LTE', 'LT', 'EQ', 'NEQ', 'IN'))");
        DB::statement('ALTER TABLE approval_policy_conditions ADD CONSTRAINT approval_policy_conditions_policy_fk FOREIGN KEY (company_id, policy_id) REFERENCES approval_policies (company_id, id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_required_check CHECK (required_approvals >= 1)');
        DB::statement("ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_on_expiry_check CHECK (on_expiry IN ('FLAG_OVERDUE', 'EXPIRE_REQUEST'))");
        DB::statement('ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_policy_fk FOREIGN KEY (company_id, policy_id) REFERENCES approval_policies (company_id, id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE approval_authorities ADD CONSTRAINT approval_authorities_ceiling_check CHECK (unlimited = (ceiling_base_amount IS NULL))');
        DB::statement('ALTER TABLE approval_authorities ADD CONSTRAINT approval_authorities_ceiling_positive_check CHECK (ceiling_base_amount IS NULL OR ceiling_base_amount >= 0)');
        DB::statement('ALTER TABLE approval_authorities ADD CONSTRAINT approval_authorities_period_check CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement('ALTER TABLE approval_authorities ADD CONSTRAINT approval_authorities_user_fk FOREIGN KEY (company_id, user_id) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE approval_authorities ADD CONSTRAINT approval_authorities_changed_by_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');

        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_distinct_check CHECK (delegator_id <> delegate_id)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_period_check CHECK (valid_to >= valid_from)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_delegator_fk FOREIGN KEY (company_id, delegator_id) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_delegate_fk FOREIGN KEY (company_id, delegate_id) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_created_by_fk FOREIGN KEY (company_id, created_by) REFERENCES users (company_id, id)');

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'INVALIDATED', 'EXPIRED'))");
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_policy_fk FOREIGN KEY (company_id, policy_id) REFERENCES approval_policies (company_id, id)');
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_submitted_by_fk FOREIGN KEY (company_id, submitted_by) REFERENCES users (company_id, id)');
        // One request in flight per subject; earlier ones stay as history.
        DB::statement("CREATE UNIQUE INDEX approval_requests_one_pending ON approval_requests (company_id, subject_type, subject_id) WHERE status = 'PENDING'");

        DB::statement("ALTER TABLE approval_request_steps ADD CONSTRAINT approval_request_steps_status_check CHECK (status IN ('WAITING', 'OPEN', 'APPROVED', 'REJECTED', 'CLOSED'))");
        DB::statement('ALTER TABLE approval_request_steps ADD CONSTRAINT approval_request_steps_request_fk FOREIGN KEY (company_id, request_id) REFERENCES approval_requests (company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE approval_request_steps ADD CONSTRAINT approval_request_steps_policy_step_fk FOREIGN KEY (company_id, policy_step_id) REFERENCES approval_policy_steps (company_id, id)');

        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_decision_check CHECK (decision IN ('APPROVED', 'REJECTED'))");
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_request_fk FOREIGN KEY (company_id, request_id) REFERENCES approval_requests (company_id, id)');
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_step_fk FOREIGN KEY (company_id, request_step_id) REFERENCES approval_request_steps (company_id, id)');
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_decided_by_fk FOREIGN KEY (company_id, decided_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_principal_fk FOREIGN KEY (company_id, principal_id) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_delegation_fk FOREIGN KEY (company_id, delegation_id) REFERENCES approval_delegations (company_id, id)');
        // A delegate never adds authority: the principal is the delegator exactly when a delegation is used.
        DB::statement('ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_principal_check CHECK ((delegation_id IS NULL) = (principal_id = decided_by))');

        // Decisions are facts: never edited, never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_approval_decision_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'approval decisions are immutable' USING ERRCODE = '23514';
            END; $$ LANGUAGE plpgsql;
            SQL);
        DB::unprepared('CREATE TRIGGER approval_decisions_immutable BEFORE UPDATE OR DELETE ON approval_decisions FOR EACH ROW EXECUTE FUNCTION prevent_approval_decision_mutation()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approval_decisions_immutable ON approval_decisions');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_approval_decision_mutation()');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_request_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_authorities');
        Schema::dropIfExists('approval_policy_steps');
        Schema::dropIfExists('approval_policy_conditions');
        Schema::dropIfExists('approval_policies');
        Schema::dropIfExists('approval_subject_settings');
    }
};
