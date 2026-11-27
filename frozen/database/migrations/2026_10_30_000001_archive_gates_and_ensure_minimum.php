<?php

use App\Services\GateManagementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gates', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });
        // Updating this persistent row takes a write lock on SQLite and a
        // row lock on MySQL. All gate creation/removal uses the same lock.
        Schema::create('gate_management_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
        });
        DB::table('gate_management_locks')->insert(['id' => 1, 'version' => 0]);
        app(GateManagementService::class)->ensureMinimum();
    }

    public function down(): void
    {
        // Provisioned gates are data and must survive rollback.
        Schema::dropIfExists('gate_management_locks');
        Schema::table('gates', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
