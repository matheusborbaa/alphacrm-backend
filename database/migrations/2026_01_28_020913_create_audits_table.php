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
        Schema::create('audits', function (Blueprint $table) {
    $table->id();

    $table->string('event'); // status_changed, sla_expired, reassigned, interaction
    $table->string('entity_type'); // Lead
    $table->unsignedBigInteger('entity_id');

    $table->unsignedBigInteger('user_id')->nullable();
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->string('source')->nullable(); // kanban, sla_job, api

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
