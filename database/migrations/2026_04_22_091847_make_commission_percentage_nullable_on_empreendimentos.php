<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('empreendimentos', 'commission_percentage')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {

                    $table->decimal('commission_percentage', 5, 2)->nullable()->default(null)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `commission_percentage` DECIMAL(5,2) NULL DEFAULT NULL');
            }
        }

        if (Schema::hasColumn('empreendimentos', 'code')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {

                    $table->string('code')->nullable()->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `code` VARCHAR(255) NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empreendimentos', 'commission_percentage')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    $table->decimal('commission_percentage', 5, 2)->default(5)->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `commission_percentage` DECIMAL(5,2) NOT NULL DEFAULT 5');
            }
        }

        if (Schema::hasColumn('empreendimentos', 'code')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    $table->string('code')->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `code` VARCHAR(255) NOT NULL');
            }
        }
    }
};
