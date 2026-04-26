<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_document_accesses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_document_id')
                  ->constrained('lead_documents')
                  ->cascadeOnDelete();

            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('action', 20)->default('download');

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->string('country', 80)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('isp', 200)->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lon', 10, 6)->nullable();

            $table->timestamp('accessed_at')->useCurrent();

            $table->index(['lead_document_id', 'accessed_at']);
            $table->index(['lead_id', 'accessed_at']);
            $table->index(['user_id', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_document_accesses');
    }
};
