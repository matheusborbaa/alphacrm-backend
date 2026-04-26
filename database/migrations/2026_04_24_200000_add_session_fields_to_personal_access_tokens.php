<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {

            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->string('device_label', 120)->nullable()->after('user_agent');

            $table->timestamp('last_confirmed_password_at')->nullable()->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'ip_address',
                'user_agent',
                'device_label',
                'last_confirmed_password_at',
            ]);
        });
    }
};
