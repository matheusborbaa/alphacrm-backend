<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wrapper around Mail::to()->send() that records every attempt (success or
 * failure) into the email_logs table for admin visibility.
 *
 * Uso básico:
 *   EmailLoggerService::send(
 *       to: $user->email,
 *       mailable: new WelcomeUserMail($user, $pwd),
 *       type: EmailLog::TYPE_WELCOME,
 *       relatedUserId: $user->id,
 *       toName: $user->name
 *   );
 *
 * Retorna bool indicando sucesso. Exceções são capturadas e gravadas.
 */
class EmailLoggerService
{
    /**
     * @param  string        $to              e-mail do destinatário
     * @param  Mailable      $mailable        instância do Mailable a enviar
     * @param  string        $type            constante EmailLog::TYPE_*
     * @param  int|null      $relatedUserId   id do usuário alvo (quando aplicável)
     * @param  string|null   $toName          nome do destinatário (opcional)
     * @param  int|null      $triggeredBy     força o triggered_by (default: Auth::id())
     * @param  bool          $rethrow         relança a exception após logar (default: true)
     * @return bool                           true se enviou, false se falhou
     */
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

        // fallback: tenta pegar $subject público do Mailable
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
            Mail::to($to)->send($mailable);

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
                // nunca deixa o logger quebrar o fluxo principal
            }

            if ($rethrow) {
                throw $e;
            }
            return false;
        }
    }
}
