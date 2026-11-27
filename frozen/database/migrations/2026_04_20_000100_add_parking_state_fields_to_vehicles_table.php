<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('category', 30)->default('faculty_staff')->after('owner_name')->index();
            $table->string('current_state', 20)->default('outside')->after('status')->index();
            $table->date('daily_count_date')->nullable()->after('current_state');
            $table->unsignedInteger('entries_today_count')->default(0)->after('daily_count_date');
            $table->unsignedInteger('exits_today_count')->default(0)->after('entries_today_count');
            $table->timestamp('first_entry_today_at')->nullable()->after('exits_today_count');
            $table->timestamp('last_exit_today_at')->nullable()->after('first_entry_today_at');
            $table->timestamp('last_entry_at')->nullable()->after('last_exit_today_at');
            $table->timestamp('last_exit_at')->nullable()->after('last_entry_at');
            $table->timestamp('last_seen_at')->nullable()->after('last_exit_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn([
                'category',
                'current_state',
                'daily_count_date',
                'entries_today_count',
                'exits_today_count',
                'first_entry_today_at',
                'last_exit_today_at',
                'last_entry_at',
                'last_exit_at',
                'last_seen_at',
            ]);
        });
    }
};

