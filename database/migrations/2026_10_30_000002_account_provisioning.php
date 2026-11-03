<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 12)->default('active');
            $table->unsignedInteger('password_policy_version')->default(1);
            $table->unsignedInteger('session_version')->default(0);
            $table->timestamp('password_changed_at')->nullable();
            $table->string('activation_hash', 64)->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->unsignedInteger('activation_attempts')->default(0);
        });
        // One-time transition. New accounts use the current policy; existing
        // hashes/roles/identities are kept until their owners authenticate.
        DB::table('users')->update(['password_policy_version' => 0]);
        Schema::create('account_security_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('setup_completed_at')->nullable();
            $table->string('setup_hash', 64)->nullable();
            $table->timestamp('setup_expires_at')->nullable();
            $table->unsignedInteger('setup_attempts')->default(0);
            $table->string('setup_email')->nullable();
            $table->string('setup_name')->nullable();
        });
        DB::table('account_security_state')->insert([
            'id' => 1, 'version' => 0,
            'setup_completed_at' => DB::table('users')->exists() ? now() : null,
        ]);
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Security setup completion must not be erased by a routine rollback.
        throw new RuntimeException('Account-security migration is forward-only; restore a full backup for rollback.');
    }
};
