<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, CRM foundation (ADRs 0098, 0105–0108): optional leads, opportunities with one accountable owner, configurable OPEN stages with history, stable
 * outcomes (OPEN, WON, LOST, ABANDONED), notes, and an append-only history of every decision.
 *
 *  - Every relationship to a company-scoped table carries company_id (tenancy is structural, not just an application filter).
 *  - Stages are never deleted (an opportunity and its history refer to them) and history rows snapshot the stage's name, so renaming or archiving a stage
 *    can never reinterpret what was recorded.
 *  - sales_events and sales_notes are append-only; a closed opportunity cannot be edited until it is reopened.
 *  - WON is a valid stored outcome but no Action can set it yet: it needs authoritative customer acceptance (ADR 0107), which does not exist until quotations do.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A contact is referenced together with its party, so a deal can never name a contact of a different party. (parties already has unique (company_id, id).)
        Schema::table('party_contacts', function (Blueprint $t): void {
            $t->unique(['party_id', 'id']);
        });

        Schema::create('sales_stages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('code', 50);
            $t->string('name', 100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('status', 10)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'id']);
        });
        DB::statement("ALTER TABLE sales_stages ADD CONSTRAINT sales_stages_shape CHECK (status IN ('ACTIVE','ARCHIVED') AND length(trim(name))>0 AND length(trim(code))>0)");

        Schema::create('sales_leads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('number', 60)->nullable();
            $t->string('status', 14)->default('NEW');
            // The original enquiry, kept as received (ADR 0105): qualification and conversion add to it and never overwrite it.
            $t->string('enquirer_name', 200);
            $t->string('enquirer_organisation', 200)->nullable();
            $t->string('enquirer_email', 200)->nullable();
            $t->string('enquirer_phone', 64)->nullable();
            $t->text('description')->nullable();
            $t->string('source', 100)->nullable();
            $t->timestampTz('received_at');
            // The identity this enquiry resolved to, once a person has chosen one (existing, restored or newly registered).
            $t->unsignedBigInteger('party_id')->nullable();
            // Lead intake may be unassigned (ADR 0106).
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->timestampTz('qualified_at')->nullable();
            $t->unsignedBigInteger('qualified_by')->nullable();
            $t->timestampTz('disqualified_at')->nullable();
            $t->unsignedBigInteger('disqualified_by')->nullable();
            $t->text('disqualification_reason')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties');
            $t->foreign(['company_id', 'assigned_to'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'qualified_by'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'disqualified_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('CREATE UNIQUE INDEX sales_leads_number_unique ON sales_leads(company_id, number) WHERE number IS NOT NULL');
        DB::statement("ALTER TABLE sales_leads ADD CONSTRAINT sales_leads_shape CHECK (status IN ('NEW','QUALIFIED','DISQUALIFIED') AND length(trim(enquirer_name))>0 AND ((status='DISQUALIFIED') = (disqualified_at IS NOT NULL AND length(trim(coalesce(disqualification_reason,'')))>0)) AND ((status='QUALIFIED') = (qualified_at IS NOT NULL)))");

        Schema::create('sales_opportunities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('number', 60)->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('party_id');
            $t->unsignedBigInteger('party_contact_id')->nullable();
            // Provenance when the deal came from an enquiry; the key makes retrying one conversion intent create nothing new (ADR 0105).
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->string('conversion_key', 100)->nullable();
            $t->string('source', 100)->nullable();
            // Exactly one accountable owner, an active member when assigned (ADR 0106). Eligibility is re-checked on assignment only: a later suspension is flagged, never silently undone.
            $t->unsignedBigInteger('owner_id');
            $t->unsignedBigInteger('stage_id');
            $t->string('outcome', 10)->default('OPEN');
            // Unknown stays unknown (ADR 0108): both null means "not yet known", never zero.
            $t->decimal('expected_amount', 19, 4)->nullable();
            $t->unsignedBigInteger('expected_currency_id')->nullable();
            $t->date('expected_close_on')->nullable();
            $t->string('next_action', 255)->nullable();
            $t->date('next_action_on')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('closure_reason')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties');
            $t->foreign(['party_id', 'party_contact_id'])->references(['party_id', 'id'])->on('party_contacts');
            $t->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'stage_id'])->references(['company_id', 'id'])->on('sales_stages');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'closed_by'])->references(['company_id', 'id'])->on('users');
            $t->foreign('expected_currency_id')->references('id')->on('currencies');
        });
        DB::statement('CREATE UNIQUE INDEX sales_opportunities_number_unique ON sales_opportunities(company_id, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX sales_opportunities_conversion_unique ON sales_opportunities(company_id, lead_id, conversion_key) WHERE conversion_key IS NOT NULL');
        DB::statement("ALTER TABLE sales_opportunities ADD CONSTRAINT sales_opportunities_shape CHECK (
            outcome IN ('OPEN','WON','LOST','ABANDONED') AND length(trim(title))>0
            AND ((outcome='OPEN') = (closed_at IS NULL))
            AND (outcome='OPEN' OR (closed_by IS NOT NULL AND length(trim(coalesce(closure_reason,'')))>0))
            AND (expected_amount IS NULL OR (expected_amount >= 0 AND expected_currency_id IS NOT NULL))
            AND (expected_amount IS NOT NULL OR expected_currency_id IS NULL)
            AND ((lead_id IS NULL) = (conversion_key IS NULL) OR conversion_key IS NULL)
        )");

        Schema::create('sales_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('opportunity_id')->nullable();
            $t->unsignedBigInteger('author_id');
            $t->text('body');
            $t->timestampTz('created_at');
            $t->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'opportunity_id'])->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'author_id'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE sales_notes ADD CONSTRAINT sales_notes_shape CHECK (((lead_id IS NULL) <> (opportunity_id IS NULL)) AND length(trim(body))>0)');

        Schema::create('sales_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('opportunity_id')->nullable();
            $t->string('event', 40);
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('occurred_at');
            $t->text('reason')->nullable();
            $t->jsonb('details')->default('{}');
            $t->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'opportunity_id'])->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'actor_id'])->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'opportunity_id']);
            $t->index(['company_id', 'lead_id']);
        });
        DB::statement('ALTER TABLE sales_events ADD CONSTRAINT sales_events_shape CHECK ((lead_id IS NOT NULL) OR (opportunity_id IS NOT NULL))');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Sales history and notes are append-only';
END $$;
CREATE TRIGGER sales_events_append_only BEFORE UPDATE OR DELETE ON sales_events FOR EACH ROW EXECUTE FUNCTION sales_append_only();
CREATE TRIGGER sales_notes_append_only BEFORE UPDATE OR DELETE ON sales_notes FOR EACH ROW EXECUTE FUNCTION sales_append_only();

-- A closed opportunity is reopened (with a reason, recorded as an event) before anything else about it changes.
CREATE OR REPLACE FUNCTION sales_closed_opportunity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'An opportunity is never deleted'; END IF;
 IF OLD.outcome<>'OPEN' AND NEW.outcome<>'OPEN' AND (to_jsonb(NEW)-'updated_at') <> (to_jsonb(OLD)-'updated_at') THEN
  RAISE EXCEPTION 'A closed opportunity must be reopened before it is changed';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_opportunity_closed_guard BEFORE UPDATE OR DELETE ON sales_opportunities FOR EACH ROW EXECUTE FUNCTION sales_closed_opportunity_guard();

CREATE OR REPLACE FUNCTION sales_lead_no_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'A lead is never deleted; disqualify it instead';
END $$;
CREATE TRIGGER sales_lead_never_deleted BEFORE DELETE ON sales_leads FOR EACH ROW EXECUTE FUNCTION sales_lead_no_delete();
CREATE OR REPLACE FUNCTION sales_stage_no_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'A stage is archived, never deleted: history refers to it';
END $$;
CREATE TRIGGER sales_stage_never_deleted BEFORE DELETE ON sales_stages FOR EACH ROW EXECUTE FUNCTION sales_stage_no_delete();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sales_stage_never_deleted ON sales_stages; DROP TRIGGER IF EXISTS sales_lead_never_deleted ON sales_leads; DROP TRIGGER IF EXISTS sales_opportunity_closed_guard ON sales_opportunities;');
        Schema::dropIfExists('sales_events');
        Schema::dropIfExists('sales_notes');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('sales_leads');
        Schema::dropIfExists('sales_stages');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_append_only(); DROP FUNCTION IF EXISTS sales_closed_opportunity_guard(); DROP FUNCTION IF EXISTS sales_lead_no_delete(); DROP FUNCTION IF EXISTS sales_stage_no_delete();');
        Schema::table('party_contacts', function (Blueprint $t): void {
            $t->dropUnique(['party_id', 'id']);
        });
    }
};
