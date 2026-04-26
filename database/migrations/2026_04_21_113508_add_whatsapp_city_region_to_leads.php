<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'whatsapp')) {
                $table->string('whatsapp')->nullable()->after('phone')->index();
            }
            if (!Schema::hasColumn('leads', 'city_of_interest')) {
                $table->string('city_of_interest')->nullable()->after('empreendimento_id');
            }
            if (!Schema::hasColumn('leads', 'region_of_interest')) {
                $table->string('region_of_interest')->nullable()->after('city_of_interest');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'region_of_interest')) {
                $table->dropColumn('region_of_interest');
            }
            if (Schema::hasColumn('leads', 'city_of_interest')) {
                $table->dropColumn('city_of_interest');
            }
            if (Schema::hasColumn('leads', 'whatsapp')) {
                $table->dropColumn('whatsapp');
            }
        });
    }
};
