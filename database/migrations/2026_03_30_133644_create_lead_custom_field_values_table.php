<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_custom_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
                ->constrained('leads')
                ->cascadeOnDelete();

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['lead_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_custom_field_values');
    }
};
