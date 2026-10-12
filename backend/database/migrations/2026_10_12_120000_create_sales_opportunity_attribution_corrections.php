<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM correction B (ADRs 0112, 0114): append-only evidence that corrects WHO the record says was responsible for a closed opportunity, without
 * reopening it and without ever changing the opportunity row, the source event, or any current fact.
 *
 * A correction targets one explicit recorded fact: the owner in an OPPORTUNITY_CREATED event (OWNER_ID) or the destination of an OWNER_CHANGED event
 * (TO_OWNER_ID). The first correction of a fact has no predecessor; each later one points to the current head, so there is exactly one chain and no branch.
 * The database checks that the target event belongs to the same opportunity, that its kind matches the field, that the recorded owner is the frozen
 * source fact, that the opportunity is closed, and that the chain stays on one fact; and it refuses any update or delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lets evidence reference an event together with the opportunity it belongs to, so a correction can never point at another deal's event.
        Schema::table('sales_events', function (Blueprint $t): void {
            $t->unique(['company_id', 'id', 'opportunity_id'], 'sales_events_company_id_opportunity_unique');
        });

        Schema::create('sales_opportunity_attribution_corrections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('opportunity_id');
            $t->unsignedBigInteger('target_event_id');
            $t->string('target_field', 12);
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->unsignedBigInteger('recorded_owner_id');
            $t->unsignedBigInteger('asserted_owner_id');
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('recorded_at');
            $t->text('reason');
            $t->unique(['company_id', 'id'], 'sales_attribution_company_id_unique');
            $t->unique(['company_id', 'id', 'opportunity_id', 'target_event_id', 'target_field'], 'sales_attribution_fact_unique');
            $t->foreign(['company_id', 'opportunity_id'], 'sales_attribution_opportunity_fk')->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'target_event_id', 'opportunity_id'], 'sales_attribution_event_fk')->references(['company_id', 'id', 'opportunity_id'])->on('sales_events');
            $t->foreign(['company_id', 'recorded_owner_id'], 'sales_attribution_recorded_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'asserted_owner_id'], 'sales_attribution_asserted_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'actor_id'], 'sales_attribution_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'predecessor_id', 'opportunity_id', 'target_event_id', 'target_field'], 'sales_attribution_predecessor_fk')
                ->references(['company_id', 'id', 'opportunity_id', 'target_event_id', 'target_field'])->on('sales_opportunity_attribution_corrections');
        });
        DB::statement("ALTER TABLE sales_opportunity_attribution_corrections ADD CONSTRAINT sales_attribution_shape CHECK (
            target_field IN ('OWNER_ID','TO_OWNER_ID') AND length(trim(reason))>0 AND length(reason)<=1000
            AND (predecessor_id IS NOT NULL OR asserted_owner_id <> recorded_owner_id)
        )");
        DB::statement('CREATE UNIQUE INDEX sales_attribution_one_root ON sales_opportunity_attribution_corrections(company_id, opportunity_id, target_event_id, target_field) WHERE predecessor_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX sales_attribution_one_successor ON sales_opportunity_attribution_corrections(company_id, predecessor_id) WHERE predecessor_id IS NOT NULL');
        DB::statement('CREATE INDEX sales_attribution_by_opportunity ON sales_opportunity_attribution_corrections(company_id, opportunity_id, recorded_at, id)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_attribution_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Attribution corrections are append-only';
END $$;
CREATE TRIGGER sales_attribution_append_only BEFORE UPDATE OR DELETE ON sales_opportunity_attribution_corrections FOR EACH ROW EXECUTE FUNCTION sales_attribution_append_only();

CREATE OR REPLACE FUNCTION sales_attribution_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ev record; deal_outcome text;
BEGIN
 SELECT event, details INTO ev FROM sales_events WHERE company_id = NEW.company_id AND id = NEW.target_event_id AND opportunity_id = NEW.opportunity_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'The corrected fact is not an event of this opportunity'; END IF;
 IF NOT ((ev.event = 'OPPORTUNITY_CREATED' AND NEW.target_field = 'OWNER_ID' AND (ev.details->>'owner_id')::bigint = NEW.recorded_owner_id)
      OR (ev.event = 'OWNER_CHANGED' AND NEW.target_field = 'TO_OWNER_ID' AND (ev.details->>'to_owner_id')::bigint = NEW.recorded_owner_id)) THEN
  RAISE EXCEPTION 'The correction must target a recorded owner fact, and name it exactly as recorded';
 END IF;
 SELECT outcome INTO deal_outcome FROM sales_opportunities WHERE company_id = NEW.company_id AND id = NEW.opportunity_id;
 IF deal_outcome IS NULL OR deal_outcome = 'OPEN' THEN RAISE EXCEPTION 'Recorded responsibility is corrected only on a closed opportunity'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_attribution_validate BEFORE INSERT ON sales_opportunity_attribution_corrections FOR EACH ROW EXECUTE FUNCTION sales_attribution_validate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('sales_opportunity_attribution_corrections')->exists()) {
            throw new RuntimeException('Refusing to drop sales_opportunity_attribution_corrections: accepted attribution corrections would be lost and recorded responsibility would no longer be auditable.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_attribution_validate ON sales_opportunity_attribution_corrections; DROP TRIGGER IF EXISTS sales_attribution_append_only ON sales_opportunity_attribution_corrections;');
        Schema::dropIfExists('sales_opportunity_attribution_corrections');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_attribution_validate(); DROP FUNCTION IF EXISTS sales_attribution_append_only();');
        Schema::table('sales_events', function (Blueprint $t): void {
            $t->dropUnique('sales_events_company_id_opportunity_unique');
        });
    }
};
