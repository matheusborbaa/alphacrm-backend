<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('deleted_at');
            $table->string('deletion_reason', 500)->nullable()->after('deleted_by_user_id');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deleted_by_user_id', 'deletion_reason']);
        });
    }
};
