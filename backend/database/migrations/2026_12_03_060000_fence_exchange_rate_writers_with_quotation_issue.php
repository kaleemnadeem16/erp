<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION exchange_rate_writer_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cid bigint;
BEGIN
 IF TG_OP = 'TRUNCATE' THEN
  RAISE EXCEPTION 'Exchange rates require scoped row maintenance' USING ERRCODE='23514';
 END IF;
 INSERT INTO company_access_generations(company_id) SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING;
 FOR cid IN SELECT company_id FROM company_access_generations ORDER BY company_id FOR UPDATE LOOP END LOOP;
 RETURN NULL;
END $$;
CREATE TRIGGER exchange_rate_writer_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON exchange_rates
 FOR EACH STATEMENT EXECUTE FUNCTION exchange_rate_writer_barrier();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE exchange_rates IN ACCESS EXCLUSIVE MODE');
        DB::unprepared('DROP TRIGGER exchange_rate_writer_barrier ON exchange_rates; DROP FUNCTION exchange_rate_writer_barrier();');
    }
};
