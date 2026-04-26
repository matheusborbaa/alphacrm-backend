<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->cascadeOnDelete();

            $table->foreignId('uploader_user_id')
                  ->constrained('users');

            $table->string('original_name', 255);

            $table->string('storage_path', 500);

            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->string('category', 50)->nullable();
            $table->string('description', 500)->nullable();

            $table->foreignId('deletion_requested_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('deletion_reason', 500)->nullable();

            $table->timestamps();

            $table->index('lead_id');
            $table->index('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_documents');
    }
};
