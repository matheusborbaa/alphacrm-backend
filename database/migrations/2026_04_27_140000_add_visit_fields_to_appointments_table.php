<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L8 + I1 — Campos específicos do fluxo de visita/agendamento.
 *
 * Modalidade  → presencial ou online
 * Endereço    → pra visita presencial
 * Atendente   → email/telefone do lead capturados na hora do agendamento
 *               (caso lead seja editado depois, queremos preservar o que foi
 *               combinado naquela visita específica)
 * Confirmação → workflow paralelo ao status: pending → confirmed → completed/no_show/cancelled
 * Token       → URL pública assinada pro lead confirmar/remarcar/cancelar sem login
 * Sync Google → external_event_id + etag pra correlacionar com Google Calendar event
 * Lembretes   → marca quando 24h e 1h antes foram disparados (idempotência)
 */
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
