<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {

            $table->boolean('enforce_on_skip')
                  ->default(false)
                  ->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->dropColumn('enforce_on_skip');
        });
    }
};
