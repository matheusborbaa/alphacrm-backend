<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
    $table->id();

    $table->string('name')->nullable();
    $table->string('phone')->index();
    $table->string('email')->nullable();

    $table->foreignId('source_id')->nullable()->constrained('lead_sources');
    $table->foreignId('status_id')->nullable()->constrained('lead_status');
    $table->foreignId('assigned_user_id')->nullable()->constrained('users');

    $table->timestamp('assigned_at')->nullable();
    $table->timestamp('sla_deadline_at')->nullable();
    $table->enum('sla_status', ['pending', 'met', 'expired'])->default('pending');

    $table->string('manychat_id')->nullable();
    $table->string('channel')->nullable();
    $table->string('campaign')->nullable();

    $table->timestamps();
});
    }

    public function down(): void
    {

    }
};
