<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up()
{
    Schema::table('appointments', function (Blueprint $table) {

        $table->enum('scope', ['private','company'])
              ->default('private')
              ->after('user_id');

        $table->unsignedBigInteger('created_by')
              ->nullable()
              ->after('scope');
    });
}

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {

        });
    }
};
