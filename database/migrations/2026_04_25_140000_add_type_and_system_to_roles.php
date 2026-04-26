<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {

            $table->enum('type', ['admin', 'gestor', 'corretor'])
                ->nullable()
                ->after('name');

            $table->boolean('is_system')
                ->default(false)
                ->after('type');

            $table->text('description')
                ->nullable()
                ->after('is_system');
        });

        DB::table('roles')->where('name', 'admin')
            ->update(['type' => 'admin', 'is_system' => true]);
        DB::table('roles')->where('name', 'gestor')
            ->update(['type' => 'gestor', 'is_system' => true]);
        DB::table('roles')->where('name', 'corretor')
            ->update(['type' => 'corretor', 'is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_system', 'description']);
        });
    }
};
