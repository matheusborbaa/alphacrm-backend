<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('deletion_reason');
            $table->timestamp('purge_at')->nullable()->after('deleted_at');

            $table->index('deleted_at');
            $table->index('purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['purge_at']);
            $table->dropColumn(['deleted_at', 'purge_at']);
        });
    }
};
