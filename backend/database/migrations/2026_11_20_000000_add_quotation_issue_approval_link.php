<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, approvals integration (ADR 0158): an issued quotation names the approval it was issued under. An issue whose draft carried a discount or final-net override that
 * needed approval records the shared approval request (company-scoped composite foreign key) and the content fingerprint that approval was given for, so the issued record
 * proves WHICH approval authorised the frozen reductions. Both columns are present together or absent (an issue with no needed approval has neither). The issue table is
 * already append-only; nothing here makes it editable, and existing issues keep NULL. Approval requests and their decisions are already append-only at the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_issues', function (Blueprint $t): void {
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->char('approval_fingerprint', 64)->nullable();
            $t->foreign(['company_id', 'approval_request_id'], 'quotation_issues_approval_fk')->references(['company_id', 'id'])->on('approval_requests');
        });
        DB::statement("ALTER TABLE quotation_issues ADD CONSTRAINT quotation_issues_approval_shape CHECK (
            (approval_request_id IS NULL) = (approval_fingerprint IS NULL) AND (approval_fingerprint IS NULL OR approval_fingerprint ~ '^[0-9a-f]{64}\$')
        )");
    }

    public function down(): void
    {
        // Refuse rather than erase evidence: the link is the record of which approval authorised an issued quotation's reductions.
        if (DB::table('quotation_issues')->whereNotNull('approval_request_id')->exists()) {
            throw new RuntimeException('Issued quotations name the approval they were issued under; rolling this migration back would erase that evidence. Plan a backout first.');
        }
        DB::statement('ALTER TABLE quotation_issues DROP CONSTRAINT IF EXISTS quotation_issues_approval_shape');
        Schema::table('quotation_issues', function (Blueprint $t): void {
            $t->dropForeign('quotation_issues_approval_fk');
            $t->dropColumn(['approval_request_id', 'approval_fingerprint']);
        });
    }
};
