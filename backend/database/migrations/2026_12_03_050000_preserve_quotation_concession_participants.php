<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE quotation_concession_participants (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id),
 revision_id bigint NOT NULL, actor_id bigint NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,revision_id,actor_id),
 FOREIGN KEY(company_id,revision_id) REFERENCES quotation_revisions(company_id,id),
 FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
);
INSERT INTO quotation_concession_participants(company_id,revision_id,actor_id)
 SELECT company_id,revision_id,actor_id FROM sales_quotation_operation_receipts
 WHERE operation IN ('quotation.create_draft','quotation.create_revision','quotation.update_draft') AND revision_id IS NOT NULL
 UNION SELECT company_id,revision_id,discount_entered_by FROM quotation_draft_lines WHERE discount_entered_by IS NOT NULL
 UNION SELECT company_id,revision_id,override_entered_by FROM quotation_draft_lines WHERE override_entered_by IS NOT NULL
 UNION SELECT company_id,revision_id,entered_by FROM quotation_draft_document_discounts
 ON CONFLICT DO NOTHING;
CREATE TRIGGER quotation_concession_participants_immutable BEFORE UPDATE OR DELETE ON quotation_concession_participants
 FOR EACH ROW EXECUTE FUNCTION sales_authority_reject_mutation();
CREATE TRIGGER quotation_concession_participants_truncate BEFORE TRUNCATE ON quotation_concession_participants
 FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_reject_mutation();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_concession_participants IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_concession_participants')->exists()) {
            throw new RuntimeException('Quotation participation evidence cannot be discarded.');
        }
        DB::statement('DROP TABLE quotation_concession_participants');
    }
};
