<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Credenciais OAuth Google por usuário. Tokens são encrypted no model.
// sync_token é o token incremental do Calendar API — evita refazer full sync a cada polling.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_google_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('email', 191)->nullable();

            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('scope')->nullable();
            $table->timestamp('expires_at')->nullable();


            $table->string('calendar_id', 191)->nullable()->default('primary');

            $table->string('sync_token', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_google_credentials');
    }
};
