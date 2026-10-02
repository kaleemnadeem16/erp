<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('ACTIVE')->after('password');
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable();
            $table->index(['company_id', 'status']);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('INVITED', 'ACTIVE', 'SUSPENDED'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_invited_by_company_fk FOREIGN KEY (company_id, invited_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_suspended_by_company_fk FOREIGN KEY (company_id, suspended_by) REFERENCES users (company_id, id)');

        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('description'));
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_suspended_by_company_fk');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_invited_by_company_fk');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn(['status', 'invited_at', 'invited_by', 'suspended_at', 'suspended_by']);
        });
    }
};
