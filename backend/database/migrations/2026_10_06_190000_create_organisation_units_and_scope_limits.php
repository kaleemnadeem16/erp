<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0089 (Q26): a company-defined tree of organisation units, who belongs to which, which resources (today warehouses) each owns, and
 * the "only my units" limit an administrator can put on a role or direct permission assigned to a person. Nothing here changes anyone's
 * access by existing: an assignment with no limit row is company-wide, exactly as every assignment already is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });
        DB::statement("ALTER TABLE organisation_units ADD CONSTRAINT organisation_units_status_check CHECK (status IN ('ACTIVE', 'ARCHIVED'))");
        DB::statement('ALTER TABLE organisation_units ADD CONSTRAINT organisation_units_not_own_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('ALTER TABLE organisation_units ADD CONSTRAINT organisation_units_parent_company_fk FOREIGN KEY (company_id, parent_id) REFERENCES organisation_units (company_id, id)');

        Schema::create('organisation_unit_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['unit_id', 'user_id']);
        });
        DB::statement('ALTER TABLE organisation_unit_members ADD CONSTRAINT organisation_unit_members_unit_company_fk FOREIGN KEY (company_id, unit_id) REFERENCES organisation_units (company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE organisation_unit_members ADD CONSTRAINT organisation_unit_members_user_company_fk FOREIGN KEY (company_id, user_id) REFERENCES users (company_id, id) ON DELETE CASCADE');

        // A resource (today a warehouse) belongs to at most one unit; resource_type is validated against a registry in code, so a new kind
        // of resource is an addition, not a migration. There is deliberately no foreign key on resource_id (it is polymorphic).
        Schema::create('organisation_unit_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('unit_id');
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['company_id', 'resource_type', 'resource_id']);
        });
        DB::statement('ALTER TABLE organisation_unit_resources ADD CONSTRAINT organisation_unit_resources_unit_company_fk FOREIGN KEY (company_id, unit_id) REFERENCES organisation_units (company_id, id) ON DELETE CASCADE');

        // "Only my units" on one assignment: a role given to a person, or a direct permission. Exactly one of role_id / permission is set.
        // The scope is a name so own-record and assigned-record scopes can be added later without a new table.
        Schema::create('access_scope_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->string('permission')->nullable();
            $table->string('scope', 16)->default('MY_UNITS');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
        DB::statement("ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_scope_check CHECK (scope IN ('MY_UNITS'))");
        DB::statement('ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_one_source_check CHECK ((role_id IS NOT NULL) <> (permission IS NOT NULL))');
        DB::statement('ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_user_company_fk FOREIGN KEY (company_id, user_id) REFERENCES users (company_id, id) ON DELETE CASCADE');
        DB::statement('CREATE UNIQUE INDEX access_scope_limits_role_unique ON access_scope_limits (company_id, user_id, role_id) WHERE role_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX access_scope_limits_permission_unique ON access_scope_limits (company_id, user_id, permission) WHERE permission IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('access_scope_limits');
        Schema::dropIfExists('organisation_unit_resources');
        Schema::dropIfExists('organisation_unit_members');
        Schema::dropIfExists('organisation_units');
    }
};
