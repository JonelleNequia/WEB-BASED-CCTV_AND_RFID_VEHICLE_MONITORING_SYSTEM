<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recovery_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('epoch')->default(0);
            $table->uuid('active_set');
        });
        DB::table('recovery_state')->insert(['id' => 1, 'epoch' => 0, 'active_set' => '00000000-0000-0000-0000-000000000000']);
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery epoch state is forward-only; use the verified recovery workflow.');
    }
};
