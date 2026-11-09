<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_installations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->uuid('installation');
            $table->uuid('generation');
        });
        Schema::create('delivery_receipts', function (Blueprint $table) {
            $table->string('operation', 64)->primary();
            $table->string('sha256', 64);
            $table->uuid('installation');
            $table->uuid('generation');
            $table->string('kind', 30);
            $table->json('result_json');
            $table->timestamp('committed_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('delivery_receipts');
        Schema::dropIfExists('delivery_installations');
    }
};
