<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A manual exchange rate is strictly positive (ADR 0012, ADR 0143's neighbour: Currency owns its master). The Action and the Form Requests refuse zero and
 * negative rates; this is the database's own refusal behind them, so a raw write cannot store a rate that would zero or invert an amount. Rows already
 * stored must satisfy it: the migration stops with a readable message rather than rewriting or deleting any historical rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::table('exchange_rates')->where('rate', '<=', 0)->count();
        if ($bad > 0) {
            throw new RuntimeException("{$bad} stored exchange rate(s) are zero or negative. Review and correct them (they are evidence, so not changed here), then re-run the migration.");
        }
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive_check CHECK (rate > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_rate_positive_check');
    }
};
