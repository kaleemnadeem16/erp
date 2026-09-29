<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('access_updated_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('credential_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['last_login_at', 'access_updated_at', 'must_change_password', 'credential_version']);
        });
    }
};
