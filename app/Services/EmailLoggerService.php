<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailLoggerService
{

    public static function send(
        string $to,
        Mailable $mailable,
        string $type = EmailLog::TYPE_OTHER,
        ?int $relatedUserId = null,
        ?string $toName = null,
        ?int $triggeredBy = null,
        bool $rethrow = false
    ): bool {
        $triggeredBy = $triggeredBy ?? Auth::id();
        $subject     = method_exists($mailable, 'envelope')
            ? (optional($mailable->envelope())->subject ?? null)
            : null;

        if ($subject === null && property_exists($mailable, 'subject')) {
            $subject = $mailable->subject ?? null;
        }

        $fromEmail = config('mail.from.address');
        $fromName  = config('mail.from.name');
        $mailClass = get_class($mailable);

        $base = [
            'to_email'             => $to,
            'to_name'              => $toName,
            'from_email'           => $fromEmail,
            'from_name'            => $fromName,
            'subject'              => $subject,
            'mail_class'           => $mailClass,
            'type'                 => $type,
            'triggered_by_user_id' => $triggeredBy,
            'related_user_id'      => $relatedUserId,
        ];

        try {

            Mail::to($to)->sendNow($mailable);

            EmailLog::create($base + [
                'status'        => EmailLog::STATUS_SENT,
                'error_message' => null,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('EmailLoggerService: falha no envio', [
                'to'    => $to,
                'type'  => $type,
                'class' => $mailClass,
                'error' => $e->getMessage(),
            ]);

            try {
                EmailLog::create($base + [
                    'status'        => EmailLog::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            } catch (Throwable $ignored) {

            }

            if ($rethrow) {
                throw $e;
            }
            return false;
        }
    }
}
