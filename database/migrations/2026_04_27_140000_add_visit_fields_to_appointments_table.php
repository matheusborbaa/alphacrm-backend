<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Visit/appointment: campos pra modalidade, endereço, dados do lead snapshotados, confirmation flow,
// sync com Google Calendar (external_event_id + etag) e lembretes idempotentes.
// Os dados do lead são copiados pro appointment pra preservar o que foi combinado mesmo se o lead mudar depois.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {

            $table->string('modality', 20)->nullable()->after('task_kind');

            $table->text('location')->nullable()->after('description');

            $table->string('attendee_email', 191)->nullable()->after('location');
            $table->string('attendee_phone', 32)->nullable()->after('attendee_email');


            $table->string('confirmation_status', 20)->default('pending')->after('status');
            $table->string('confirmation_token', 64)->nullable()->unique()->after('confirmation_status');
            $table->timestamp('lead_confirmed_at')->nullable()->after('confirmation_token');
            $table->text('cancellation_reason')->nullable()->after('lead_confirmed_at');


            $table->string('meeting_url', 500)->nullable()->after('cancellation_reason');


            $table->string('external_event_id', 255)->nullable()->after('meeting_url');
            $table->string('external_event_etag', 191)->nullable()->after('external_event_id');
            $table->timestamp('last_synced_at')->nullable()->after('external_event_etag');
            $table->text('last_sync_error')->nullable()->after('last_synced_at');


            $table->timestamp('reminder_sent_24h_at')->nullable()->after('last_sync_error');
            $table->timestamp('reminder_sent_1h_at')->nullable()->after('reminder_sent_24h_at');


            $table->index('confirmation_status', 'idx_appt_confirm_status');
            $table->index('starts_at', 'idx_appt_starts_at');
            $table->index('external_event_id', 'idx_appt_external_event');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('idx_appt_confirm_status');
            $table->dropIndex('idx_appt_starts_at');
            $table->dropIndex('idx_appt_external_event');

            $table->dropColumn([
                'modality',
                'location',
                'attendee_email',
                'attendee_phone',
                'confirmation_status',
                'confirmation_token',
                'lead_confirmed_at',
                'cancellation_reason',
                'meeting_url',
                'external_event_id',
                'external_event_etag',
                'last_synced_at',
                'last_sync_error',
                'reminder_sent_24h_at',
                'reminder_sent_1h_at',
            ]);
        });
    }
};
