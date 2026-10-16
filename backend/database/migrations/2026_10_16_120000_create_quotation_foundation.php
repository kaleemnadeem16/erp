<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, QL0 (R5 slice QL0, ADRs 0098, 0099, 0109, 0122–0124): the quotation aggregate's identities and its editable draft — and nothing that is not yet
 * knowable.
 *
 *  - A FAMILY is the stable business quotation; its REVISIONS are explicit, ordinal and lineage-linked, not unrelated documents. The family carries the one
 *    current CRM association (optional, never mandatory) and every change to it is an append-only event the database insists on (ADR 0099).
 *  - LOGICAL SCOPES and CHOICE GROUPS are family-level identities that survive revisions: a scope is an intended deliverable, never an Item or Assembly id, so
 *    the same Item twice is two scopes and an alternative shares an overlap anchor with what it competes with. Both are append-only.
 *  - A DRAFT LINE is the customer-facing selling line, typed by source (direct Item, service Item, Assembly version, governed manual) and kept apart from any
 *    estimating evidence. It holds no price, cost, tax or FX: entered money needs the dual-money evidence of ADR 0012/R4 and arrives with QL1, which is also
 *    where FinancialActivity is registered. A draft can be incomplete; completeness is a candidate-time gate.
 *  - A revision is DRAFT or CANCELLED_DRAFT here. ISSUED, the official number, candidates, adoptions, issues, receipts and offer events belong to QL2/QL3/QL4
 *    and arrive with their guards: a state with no evidence behind it is not added early.
 *  - The database guards what no caller may bypass: tenancy by composite keys, source integrity per kind, draft-only line writes, immutable cancelled and
 *    identity facts, a family-local revision ordinal allocated under the family lock, an input generation that moves with every material change, and an
 *    association that always equals the latest recorded event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_families', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedInteger('revision_generation')->default(0);
            $t->unsignedBigInteger('current_opportunity_id')->nullable();
            $t->unsignedInteger('association_generation')->default(0);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['company_id', 'id'], 'quotation_families_company_id_unique');
            $t->foreign(['company_id', 'current_opportunity_id'], 'quotation_families_opportunity_fk')->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'created_by'], 'quotation_families_created_by_fk')->references(['company_id', 'id'])->on('users');
        });

        Schema::create('quotation_family_association_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedInteger('generation');
            $t->unsignedBigInteger('old_opportunity_id')->nullable();
            $t->unsignedBigInteger('new_opportunity_id')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('occurred_at');
            $t->text('reason')->nullable();
            $t->unique(['company_id', 'family_id', 'generation'], 'quotation_association_generation_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_association_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'old_opportunity_id'], 'quotation_association_old_fk')->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'new_opportunity_id'], 'quotation_association_new_fk')->references(['company_id', 'id'])->on('sales_opportunities');
            $t->foreign(['company_id', 'actor_id'], 'quotation_association_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE quotation_family_association_events ADD CONSTRAINT quotation_association_shape CHECK (
            generation >= 1 AND old_opportunity_id IS DISTINCT FROM new_opportunity_id AND (reason IS NULL OR length(trim(reason)) > 0)
        )');

        Schema::create('quotation_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            // Allocated by the database under the family lock; a caller may omit it.
            $t->unsignedInteger('revision_ordinal')->nullable();
            $t->string('state', 16)->default('DRAFT');
            $t->unsignedInteger('input_generation')->default(1);
            // Nullable only because a draft may be incomplete; completeness is checked when a candidate is frozen.
            $t->unsignedBigInteger('recipient_party_id')->nullable();
            // One currency per quotation, explicit even in a draft (it defaults to the company base currency in the Action that creates the draft).
            $t->foreignId('currency_id')->constrained('currencies');
            $t->date('pricing_date')->nullable();
            $t->date('tax_estimate_date')->nullable();
            $t->date('valid_through_local_date')->nullable();
            $t->unsignedBigInteger('predecessor_revision_id')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id'], 'quotation_revisions_company_id_unique');
            $t->unique(['company_id', 'family_id', 'id'], 'quotation_revisions_family_unique');
            $t->unique(['company_id', 'family_id', 'revision_ordinal'], 'quotation_revisions_ordinal_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_revisions_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'recipient_party_id'], 'quotation_revisions_party_fk')->references(['company_id', 'id'])->on('parties');
            $t->foreign(['company_id', 'family_id', 'predecessor_revision_id'], 'quotation_revisions_predecessor_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'created_by'], 'quotation_revisions_created_by_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'cancelled_by'], 'quotation_revisions_cancelled_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_shape CHECK (
            state IN ('DRAFT','CANCELLED_DRAFT') AND input_generation >= 1 AND (revision_ordinal IS NULL OR revision_ordinal >= 1)
            AND (state = 'CANCELLED_DRAFT') = (cancelled_at IS NOT NULL)
            AND (cancelled_at IS NULL) = (cancelled_by IS NULL)
            AND (state = 'DRAFT' OR length(trim(coalesce(cancellation_reason, ''))) > 0)
            AND (state = 'CANCELLED_DRAFT' OR cancellation_reason IS NULL)
        )");

        Schema::create('quotation_logical_scopes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            // The original intent or provenance of this deliverable, opaque to the database. Never an Item or Assembly id.
            $t->string('intent_ref', 80);
            // Shared by alternatives and by a scope that supersedes another; null means the scope is its own anchor.
            $t->unsignedBigInteger('overlap_anchor_id')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'quotation_scopes_company_id_unique');
            $t->unique(['company_id', 'family_id', 'id'], 'quotation_scopes_family_unique');
            $t->unique(['company_id', 'family_id', 'intent_ref'], 'quotation_scopes_intent_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_scopes_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'family_id', 'overlap_anchor_id'], 'quotation_scopes_anchor_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_logical_scopes');
            $t->foreign(['company_id', 'created_by'], 'quotation_scopes_created_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE quotation_logical_scopes ADD CONSTRAINT quotation_scopes_shape CHECK (length(trim(intent_ref)) > 0)');

        Schema::create('quotation_choice_groups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->string('intent_ref', 80);
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'quotation_choice_groups_company_id_unique');
            $t->unique(['company_id', 'family_id', 'id'], 'quotation_choice_groups_family_unique');
            $t->unique(['company_id', 'family_id', 'intent_ref'], 'quotation_choice_groups_intent_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_choice_groups_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'created_by'], 'quotation_choice_groups_created_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE quotation_choice_groups ADD CONSTRAINT quotation_choice_groups_shape CHECK (length(trim(intent_ref)) > 0)');

        Schema::create('quotation_draft_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('logical_scope_id');
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('section_title', 200)->nullable();
            $t->text('description');
            // BASE is in the offer; OPTIONAL is offered outside the base; CHOOSE_ONE competes inside its choice group, one of which is the baseline.
            $t->string('membership', 12)->default('BASE');
            $t->unsignedBigInteger('choice_group_id')->nullable();
            $t->boolean('is_baseline')->default(false);
            // Same vocabulary as the pricing kernel (PricingSourceKind).
            $t->string('source_kind', 16);
            $t->unsignedBigInteger('item_id')->nullable();
            $t->unsignedBigInteger('assembly_version_id')->nullable();
            $t->string('manual_measure', 100)->nullable();
            // The offered quantity: four places like every transaction column. The column cannot refuse a finer value (it would round it), so the model hook
            // refuses it through the shared quantity contract before it can reach here.
            $t->decimal('offered_quantity', 19, 4);
            $t->unsignedBigInteger('offered_uom_id')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['company_id', 'id'], 'quotation_draft_lines_company_id_unique');
            $t->unique(['company_id', 'revision_id', 'logical_scope_id'], 'quotation_draft_lines_scope_unique');
            $t->foreign(['company_id', 'family_id', 'revision_id'], 'quotation_draft_lines_revision_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'family_id', 'logical_scope_id'], 'quotation_draft_lines_scope_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_logical_scopes');
            $t->foreign(['company_id', 'family_id', 'choice_group_id'], 'quotation_draft_lines_group_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_choice_groups');
            $t->foreign(['company_id', 'item_id'], 'quotation_draft_lines_item_fk')->references(['company_id', 'id'])->on('items');
            $t->foreign('assembly_version_id', 'quotation_draft_lines_assembly_version_fk')->references('id')->on('assembly_versions');
            $t->foreign(['company_id', 'offered_uom_id'], 'quotation_draft_lines_uom_fk')->references(['company_id', 'id'])->on('uoms');
            $t->foreign(['company_id', 'created_by'], 'quotation_draft_lines_created_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_draft_lines ADD CONSTRAINT quotation_draft_lines_shape CHECK (
            length(trim(description)) > 0
            AND offered_quantity > 0 AND offered_quantity <= 999999999999999.9999
            AND membership IN ('BASE','OPTIONAL','CHOOSE_ONE')
            AND (membership = 'CHOOSE_ONE') = (choice_group_id IS NOT NULL)
            AND (membership = 'CHOOSE_ONE' OR is_baseline = false)
            AND source_kind IN ('DIRECT_ITEM','SERVICE_ITEM','ASSEMBLY_WORK','MANUAL')
            AND (
                (source_kind IN ('DIRECT_ITEM','SERVICE_ITEM') AND item_id IS NOT NULL AND assembly_version_id IS NULL AND manual_measure IS NULL AND offered_uom_id IS NOT NULL)
             OR (source_kind = 'ASSEMBLY_WORK' AND assembly_version_id IS NOT NULL AND item_id IS NULL AND manual_measure IS NULL AND offered_uom_id IS NOT NULL)
             OR (source_kind = 'MANUAL' AND item_id IS NULL AND assembly_version_id IS NULL AND length(trim(coalesce(manual_measure, ''))) > 0 AND offered_uom_id IS NULL)
            )
        )");
        DB::statement('CREATE UNIQUE INDEX quotation_draft_lines_one_baseline ON quotation_draft_lines (company_id, revision_id, choice_group_id) WHERE is_baseline');
        DB::statement('CREATE INDEX quotation_draft_lines_by_revision ON quotation_draft_lines (company_id, revision_id, sort_order, id)');
        DB::statement('CREATE INDEX quotation_revisions_by_family ON quotation_revisions (company_id, family_id, revision_ordinal)');
        DB::statement('CREATE INDEX quotation_revisions_by_recipient ON quotation_revisions (company_id, recipient_party_id) WHERE recipient_party_id IS NOT NULL');
        DB::statement('CREATE INDEX quotation_families_by_opportunity ON quotation_families (company_id, current_opportunity_id) WHERE current_opportunity_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
-- Identities are history: a family, a scope or a choice group is never deleted, and a scope or group never changes.
CREATE OR REPLACE FUNCTION quotation_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION '% rows are history and cannot be deleted', TG_TABLE_NAME USING ERRCODE = '23514'; END IF;
 IF TG_TABLE_NAME = 'quotation_families' THEN
  IF ROW(NEW.company_id, NEW.created_by) IS DISTINCT FROM ROW(OLD.company_id, OLD.created_by) THEN RAISE EXCEPTION 'A quotation family keeps its company and creator' USING ERRCODE = '23514'; END IF;
  IF NEW.revision_generation < OLD.revision_generation OR NEW.association_generation < OLD.association_generation THEN RAISE EXCEPTION 'Quotation generations only move forward' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_families_guard BEFORE UPDATE OR DELETE ON quotation_families FOR EACH ROW EXECUTE FUNCTION quotation_identity_guard();
CREATE TRIGGER quotation_scopes_guard BEFORE UPDATE OR DELETE ON quotation_logical_scopes FOR EACH ROW EXECUTE FUNCTION quotation_identity_guard();
CREATE TRIGGER quotation_choice_groups_guard BEFORE UPDATE OR DELETE ON quotation_choice_groups FOR EACH ROW EXECUTE FUNCTION quotation_identity_guard();

-- Association history is append-only, forms one unbroken chain, and the family must always equal its latest event (checked at commit so an event and the
-- family update may be written in either order).
CREATE OR REPLACE FUNCTION quotation_association_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE last_event record;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Quotation association history is append-only' USING ERRCODE = '23514'; END IF;
 SELECT generation, new_opportunity_id INTO last_event FROM quotation_family_association_events
  WHERE company_id = NEW.company_id AND family_id = NEW.family_id ORDER BY generation DESC LIMIT 1;
 IF NOT FOUND THEN
  IF NEW.generation <> 1 OR NEW.old_opportunity_id IS NOT NULL THEN RAISE EXCEPTION 'The first association event starts from no opportunity' USING ERRCODE = '23514'; END IF;
 ELSIF NEW.generation <> last_event.generation + 1 OR NEW.old_opportunity_id IS DISTINCT FROM last_event.new_opportunity_id THEN
  RAISE EXCEPTION 'Association events must continue the chain' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_association_event_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_family_association_events FOR EACH ROW EXECUTE FUNCTION quotation_association_event_guard();

CREATE OR REPLACE FUNCTION quotation_family_association_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE last_event record; fam record; expected_generation integer := 0; expected_opportunity bigint := NULL;
BEGIN
 -- A deferred trigger sees the row as it was when queued, so the family is read again as it stands at the check.
 SELECT association_generation, current_opportunity_id INTO fam FROM quotation_families WHERE company_id = NEW.company_id AND id = NEW.id;
 SELECT generation, new_opportunity_id INTO last_event FROM quotation_family_association_events
  WHERE company_id = NEW.company_id AND family_id = NEW.id ORDER BY generation DESC LIMIT 1;
 IF FOUND THEN expected_generation := last_event.generation; expected_opportunity := last_event.new_opportunity_id; END IF;
 IF fam.association_generation <> expected_generation OR fam.current_opportunity_id IS DISTINCT FROM expected_opportunity THEN
  RAISE EXCEPTION 'A quotation family association must equal its latest recorded association event' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER quotation_family_association_check AFTER INSERT OR UPDATE ON quotation_families DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_association_check();

CREATE OR REPLACE FUNCTION quotation_association_event_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE fam record;
BEGIN
 SELECT association_generation, current_opportunity_id INTO fam FROM quotation_families WHERE company_id = NEW.company_id AND id = NEW.family_id;
 IF NEW.generation > fam.association_generation THEN RAISE EXCEPTION 'An association event must be reflected on its family' USING ERRCODE = '23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER quotation_association_event_check AFTER INSERT ON quotation_family_association_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_association_event_check();

-- Revisions: ordinal allocated under the family lock; cancelled and identity facts immutable; the input generation moves with every material change.
CREATE OR REPLACE FUNCTION quotation_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allocated integer;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Quotation revisions are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.state <> 'DRAFT' THEN RAISE EXCEPTION 'A quotation revision begins as a draft' USING ERRCODE = '23514'; END IF;
  UPDATE quotation_families SET revision_generation = revision_generation + 1, updated_at = now()
   WHERE company_id = NEW.company_id AND id = NEW.family_id RETURNING revision_generation INTO allocated;
  IF NEW.revision_ordinal IS NOT NULL AND NEW.revision_ordinal <> allocated THEN RAISE EXCEPTION 'The revision ordinal is allocated by the family' USING ERRCODE = '23514'; END IF;
  NEW.revision_ordinal := allocated;
  NEW.input_generation := 1;
  RETURN NEW;
 END IF;
 IF OLD.state <> 'DRAFT' THEN RAISE EXCEPTION 'A cancelled quotation draft is immutable' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.family_id, NEW.revision_ordinal, NEW.predecessor_revision_id, NEW.created_by, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.family_id, OLD.revision_ordinal, OLD.predecessor_revision_id, OLD.created_by, OLD.created_at) THEN
  RAISE EXCEPTION 'A quotation revision keeps its identity' USING ERRCODE = '23514';
 END IF;
 IF NEW.input_generation <> OLD.input_generation AND NEW.input_generation <> OLD.input_generation + 1 THEN
  RAISE EXCEPTION 'The input generation only advances one step at a time' USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'DRAFT' AND ROW(NEW.recipient_party_id, NEW.currency_id, NEW.pricing_date, NEW.tax_estimate_date, NEW.valid_through_local_date)
    IS DISTINCT FROM ROW(OLD.recipient_party_id, OLD.currency_id, OLD.pricing_date, OLD.tax_estimate_date, OLD.valid_through_local_date)
    AND NEW.input_generation = OLD.input_generation THEN
  NEW.input_generation := OLD.input_generation + 1;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_revision_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_revisions FOR EACH ROW EXECUTE FUNCTION quotation_revision_guard();

-- Draft lines: written only while their revision is a draft; typed source integrity; identity fixed; every change advances the revision's generation.
CREATE OR REPLACE FUNCTION quotation_draft_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; rev_id bigint; item_type text; assembly_company bigint;
BEGIN
 rev_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
 SELECT state INTO rev_state FROM quotation_revisions WHERE id = rev_id FOR SHARE;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Lines can only be written on a draft revision' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 IF TG_OP = 'UPDATE' AND ROW(NEW.company_id, NEW.family_id, NEW.revision_id, NEW.logical_scope_id, NEW.created_by, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.family_id, OLD.revision_id, OLD.logical_scope_id, OLD.created_by, OLD.created_at) THEN
  RAISE EXCEPTION 'A draft line keeps its revision and logical scope' USING ERRCODE = '23514';
 END IF;
 IF NEW.item_id IS NOT NULL THEN
  SELECT type INTO item_type FROM items WHERE company_id = NEW.company_id AND id = NEW.item_id;
  IF NEW.source_kind = 'SERVICE_ITEM' AND item_type IS DISTINCT FROM 'SERVICE' THEN RAISE EXCEPTION 'A service source must be a service item' USING ERRCODE = '23514'; END IF;
  IF NEW.source_kind = 'DIRECT_ITEM' AND item_type IN ('SERVICE','LABOUR','PLANT') THEN RAISE EXCEPTION 'A direct item source cannot be a service or a resource' USING ERRCODE = '23514'; END IF;
 END IF;
 IF NEW.assembly_version_id IS NOT NULL THEN
  SELECT a.company_id INTO assembly_company FROM assembly_versions v JOIN assemblies a ON a.id = v.assembly_id WHERE v.id = NEW.assembly_version_id;
  IF assembly_company IS DISTINCT FROM NEW.company_id THEN RAISE EXCEPTION 'The assembly version belongs to another company' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_draft_line_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_draft_lines FOR EACH ROW EXECUTE FUNCTION quotation_draft_line_guard();

CREATE OR REPLACE FUNCTION quotation_draft_line_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE quotation_revisions SET input_generation = input_generation + 1, updated_at = now() WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
 RETURN NULL;
END $$;
CREATE TRIGGER quotation_draft_line_generation AFTER INSERT OR UPDATE OR DELETE ON quotation_draft_lines FOR EACH ROW EXECUTE FUNCTION quotation_draft_line_generation();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase quotation history: a family, its association events or any revision beyond a bare draft may already be evidence.
        if (Schema::hasTable('quotation_families') && (DB::table('quotation_revisions')->exists() || DB::table('quotation_families')->exists())) {
            throw new RuntimeException('Quotation families or revisions exist; rolling this migration back would erase quotation history. Plan a backout first.');
        }
        foreach (['quotation_draft_line_generation' => 'quotation_draft_lines', 'quotation_draft_line_guard' => 'quotation_draft_lines', 'quotation_revision_guard' => 'quotation_revisions',
            'quotation_association_event_check' => 'quotation_family_association_events', 'quotation_association_event_guard' => 'quotation_family_association_events',
            'quotation_family_association_check' => 'quotation_families', 'quotation_choice_groups_guard' => 'quotation_choice_groups', 'quotation_scopes_guard' => 'quotation_logical_scopes',
            'quotation_families_guard' => 'quotation_families'] as $trigger => $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        foreach (['quotation_draft_line_generation', 'quotation_draft_line_guard', 'quotation_revision_guard', 'quotation_association_event_check', 'quotation_association_event_guard',
            'quotation_family_association_check', 'quotation_identity_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
        Schema::dropIfExists('quotation_draft_lines');
        Schema::dropIfExists('quotation_choice_groups');
        Schema::dropIfExists('quotation_logical_scopes');
        Schema::dropIfExists('quotation_revisions');
        Schema::dropIfExists('quotation_family_association_events');
        Schema::dropIfExists('quotation_families');
    }
};
