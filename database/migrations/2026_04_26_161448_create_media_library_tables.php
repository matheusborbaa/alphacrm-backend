<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('media_folders')
                ->cascadeOnDelete();
            $table->string('name', 200);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('parent_id');
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('folder_id')
                ->nullable()
                ->constrained('media_folders')
                ->cascadeOnDelete();

            $table->string('name', 200);

            $table->string('original_name', 255);

            $table->string('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('media_folders');
    }
};
