<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_required_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_status_id')
                ->nullable()
                ->constrained('lead_status')
                ->cascadeOnDelete();

            $table->foreignId('lead_substatus_id')
                ->nullable()
                ->constrained('lead_substatus')
                ->cascadeOnDelete();

            $table->string('lead_column')->nullable();

            $table->foreignId('custom_field_id')
                ->nullable()
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->boolean('required')->default(true);

            $table->timestamps();

            $table->index(['lead_status_id']);
            $table->index(['lead_substatus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_required_fields');
    }
};
