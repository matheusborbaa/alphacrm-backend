<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->string('slug')->unique();

            $table->enum('type', [
                'text',
                'textarea',
                'number',
                'date',
                'select',
                'checkbox',
            ])->default('text');

            $table->json('options')->nullable();

            $table->boolean('active')->default(true);

            $table->integer('order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
