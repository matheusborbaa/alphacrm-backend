<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Models\UserGoogleCredential;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * I1 — Wrapper de integração com Google Calendar + Meet.
 *
 * Tudo blindado contra a ausência do pacote google/apiclient: se o
 * pacote não estiver instalado, isInstalled() retorna false e os outros
 * métodos viram noop, deixando o sistema funcionando normalmente sem sync.
 */
class GoogleCalendarService
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function isInstalled(): bool
    {
        return class_exists(\Google\Client::class)
            && class_exists(\Google\Service\Calendar::class);
    }

    public function isConfigured(): bool
    {
        return $this->isInstalled()
            && !empty($this->getCredential('client_id'))
            && !empty($this->getCredential('client_secret'))
            && !empty($this->getCredential('redirect_uri'));
    }

    /**
     * Lê credencial do banco (Settings) primeiro, fallback pra .env (services.google.*).
     * Permite admin gerenciar via UI sem precisar SSH no servidor.
     */
    private function getCredential(string $key): ?string
    {
        $settingMap = [
            'client_id'         => 'google_client_id',
            'client_secret'     => 'google_client_secret',
            'redirect_uri'      => 'google_redirect_uri',
            'frontend_callback' => 'google_frontend_callback',
        ];

        $settingKey = $settingMap[$key] ?? null;
        if ($settingKey) {
            $val = \App\Models\Setting::get($settingKey);
            if (!empty($val)) return (string) $val;
        }

        return config('services.google.' . $key);
    }

    public function isUserConnected(User $user): bool
    {
        return $user->googleCredential()->exists();
    }


    public function buildAuthUrl(int $userId, string $stateNonce): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Google Calendar não está configurado. Configure GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI no .env');
        }

        $client = $this->newRawClient();
        $state = base64_encode(json_encode(['uid' => $userId, 'nonce' => $stateNonce]));
        $client->setState($state);
        return $client->createAuthUrl();
    }


    public function handleCallback(string $code, string $state): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Google não configurado.');
        }

        $decoded = json_decode(base64_decode($state), true);
        $userId = (int) ($decoded['uid'] ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('State inválido.');
        }

        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        $client = $this->newRawClient();
        $tokens = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokens['error'])) {
            throw new \RuntimeException('Falha na autenticação Google: ' . ($tokens['error_description'] ?? $tokens['error']));
        }


        $email = null;
        try {
            $client->setAccessToken($tokens);
            $oauth = new \Google\Service\Oauth2($client);
            $info  = $oauth->userinfo->get();
            $email = $info->getEmail();
        } catch (\Throwable $e) {
            Log::warning('[google] Não conseguiu obter email do usuário Google: ' . $e->getMessage());
        }

        $cred = UserGoogleCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email'         => $email,
                'access_token'  => $tokens['access_token'] ?? '',
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'scope'         => $tokens['scope'] ?? null,
                'expires_at'    => isset($tokens['expires_in'])
                    ? Carbon::now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'calendar_id'   => 'primary',
            ]
        );

        return ['user_id' => $user->id, 'email' => $email];
    }


    public function disconnect(User $user): bool
    {
        $cred = $user->googleCredential;
        if (!$cred) return false;

        try {
            $client = $this->getClient($user);
            if ($client) $client->revokeToken();
        } catch (\Throwable $e) {
            Log::warning('[google] Falha ao revogar token: ' . $e->getMessage());
        }

        $cred->delete();
        return true;
    }


    public function getClient(User $user): ?\Google\Client
    {
        if (!$this->isConfigured()) return null;

        $cred = $user->googleCredential;
        if (!$cred) return null;

        $client = $this->newRawClient();
        $client->setAccessToken([
            'access_token'  => $cred->access_token,
            'refresh_token' => $cred->refresh_token,
            'expires_in'    => $cred->expires_at ? max(1, $cred->expires_at->diffInSeconds(now(), false)) : 3600,
            'created'       => $cred->updated_at?->getTimestamp() ?? time(),
        ]);


        if ($client->isAccessTokenExpired()) {
            if (!$cred->refresh_token) {
                Log::warning('[google] Access token expirado e sem refresh_token; usuário precisa reconectar', ['user_id' => $user->id]);
                return null;
            }
            try {
                $newTokens = $client->fetchAccessTokenWithRefreshToken($cred->refresh_token);
                if (isset($newTokens['error'])) {
                    Log::error('[google] Falha no refresh: ' . json_encode($newTokens));
                    $cred->update(['last_sync_error' => 'refresh failed: ' . ($newTokens['error_description'] ?? $newTokens['error'])]);
                    return null;
                }
                $cred->update([
                    'access_token' => $newTokens['access_token'],
                    'expires_at'   => isset($newTokens['expires_in'])
                        ? now()->addSeconds((int) $newTokens['expires_in'])
                        : null,
                    'last_sync_error' => null,
                ]);
            } catch (\Throwable $e) {
                Log::error('[google] Exception no refresh: ' . $e->getMessage());
                return null;
            }
        }

        return $client;
    }


    public function pushAppointment(Appointment $appt): ?array
    {
        if (!$this->isConfigured()) return null;
        if (!$appt->user_id) return null;

        $user = User::find($appt->user_id);
        if (!$user) return null;

        if (!$this->isUserConnected($user)) return null;

        $client = $this->getClient($user);
        if (!$client) return null;

        try {
            $service = new \Google\Service\Calendar($client);
            $cred    = $user->googleCredential;
            $calId   = $cred->calendar_id ?: 'primary';

            $event = $this->buildEvent($appt);

            if ($appt->external_event_id) {

                $updated = $service->events->update($calId, $appt->external_event_id, $event, [
                    'conferenceDataVersion' => 1,
                    'sendUpdates' => 'all',
                ]);
                $this->updateAppointmentFromEvent($appt, $updated);
                return ['updated' => true, 'event_id' => $updated->getId()];
            }


            $created = $service->events->insert($calId, $event, [
                'conferenceDataVersion' => 1,
                'sendUpdates' => 'all',
            ]);
            $this->updateAppointmentFromEvent($appt, $created);
            return ['created' => true, 'event_id' => $created->getId()];
        } catch (\Throwable $e) {
            Log::error('[google] pushAppointment falhou', [
                'appt_id' => $appt->id,
                'error'   => $e->getMessage(),
            ]);
            $appt->update(['last_sync_error' => substr($e->getMessage(), 0, 1000)]);
            return null;
        }
    }


    public function deleteAppointment(Appointment $appt): bool
    {
        if (!$this->isConfigured()) return false;
        if (!$appt->external_event_id || !$appt->user_id) return false;

        $user = User::find($appt->user_id);
        if (!$user || !$this->isUserConnected($user)) return false;

        $client = $this->getClient($user);
        if (!$client) return false;

        try {
            $service = new \Google\Service\Calendar($client);
            $calId   = $user->googleCredential->calendar_id ?: 'primary';
            $service->events->delete($calId, $appt->external_event_id, ['sendUpdates' => 'all']);
            $appt->update(['external_event_id' => null, 'external_event_etag' => null]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[google] deleteAppointment falhou', [
                'appt_id'  => $appt->id,
                'event_id' => $appt->external_event_id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }


    public function pullChangesForUser(User $user): array
    {
        if (!$this->isConfigured()) return ['fetched' => 0, 'updated' => 0, 'cancelled' => 0];

        $cred = $user->googleCredential;
        if (!$cred) return ['fetched' => 0, 'updated' => 0, 'cancelled' => 0];

        $client = $this->getClient($user);
        if (!$client) return ['fetched' => 0, 'updated' => 0, 'cancelled' => 0];

        $service = new \Google\Service\Calendar($client);
        $calId   = $cred->calendar_id ?: 'primary';

        $fetched = $updated = $cancelled = 0;

        try {
            $params = [];
            if ($cred->sync_token) {

                $params['syncToken'] = $cred->sync_token;
            } else {

                $params['timeMin'] = now()->subDay()->toIso8601String();
                $params['singleEvents'] = true;
                $params['orderBy'] = 'startTime';
            }

            do {
                $events = $service->events->listEvents($calId, $params);
                foreach ($events->getItems() as $ev) {
                    $fetched++;
                    $changed = $this->applyIncomingEvent($user, $ev);
                    if ($changed === 'updated')   $updated++;
                    if ($changed === 'cancelled') $cancelled++;
                }

                $params['pageToken'] = $events->getNextPageToken();
            } while ($params['pageToken']);


            $cred->update([
                'sync_token'      => $events->getNextSyncToken() ?: $cred->sync_token,
                'last_synced_at'  => now(),
                'last_sync_error' => null,
            ]);
        } catch (\Throwable $e) {

            $msg = $e->getMessage();
            if (str_contains($msg, '410') || stripos($msg, 'gone') !== false) {
                $cred->update(['sync_token' => null]);
                Log::info('[google] Sync token expirou pra user ' . $user->id . ', forçando full sync no próximo run.');
            } else {
                Log::error('[google] pullChangesForUser falhou', ['user_id' => $user->id, 'error' => $msg]);
                $cred->update(['last_sync_error' => substr($msg, 0, 1000)]);
            }
        }

        return compact('fetched', 'updated', 'cancelled');
    }


    private function applyIncomingEvent(User $user, $ev): string
    {
        $eventId = $ev->getId();
        if (!$eventId) return 'skip';

        $appt = Appointment::where('external_event_id', $eventId)->first();


        if ($ev->getStatus() === 'cancelled') {
            if ($appt && $appt->confirmation_status !== Appointment::CONFIRM_CANCELLED) {
                $appt->update([
                    'confirmation_status' => Appointment::CONFIRM_CANCELLED,
                    'cancellation_reason' => 'Cancelado via Google Calendar',
                    'status'              => 'cancelled',
                    'last_synced_at'      => now(),
                ]);
                return 'cancelled';
            }
            return 'skip';
        }

        if (!$appt) {

            return 'skip';
        }


        $remoteEtag = $ev->getEtag();
        if ($appt->external_event_etag === $remoteEtag) return 'skip';


        $start = $ev->getStart()?->getDateTime() ?: $ev->getStart()?->getDate();
        $end   = $ev->getEnd()?->getDateTime()   ?: $ev->getEnd()?->getDate();

        $update = [
            'external_event_etag' => $remoteEtag,
            'last_synced_at'      => now(),
        ];

        if ($start) $update['starts_at'] = Carbon::parse($start);
        if ($end)   $update['ends_at']   = Carbon::parse($end);
        if ($ev->getSummary())  $update['title']    = $ev->getSummary();
        if ($ev->getLocation()) $update['location'] = $ev->getLocation();

        $appt->update($update);
        return 'updated';
    }


    private function buildEvent(Appointment $appt)
    {
        $tz = config('services.google.timezone', 'America/Sao_Paulo');

        $start = $appt->starts_at ?: $appt->due_at ?: now()->addHour();
        $end   = $appt->ends_at   ?: $start->copy()->addHour();

        $event = new \Google\Service\Calendar\Event();
        $event->setSummary($appt->title ?: 'Visita');
        $event->setDescription($this->composeDescription($appt));

        if ($appt->modality === Appointment::MODALITY_PRESENCIAL && $appt->location) {
            $event->setLocation($appt->location);
        }

        $startDt = new \Google\Service\Calendar\EventDateTime();
        $startDt->setDateTime(Carbon::parse($start)->toRfc3339String());
        $startDt->setTimeZone($tz);
        $event->setStart($startDt);

        $endDt = new \Google\Service\Calendar\EventDateTime();
        $endDt->setDateTime(Carbon::parse($end)->toRfc3339String());
        $endDt->setTimeZone($tz);
        $event->setEnd($endDt);


        if ($appt->attendee_email) {
            $att = new \Google\Service\Calendar\EventAttendee();
            $att->setEmail($appt->attendee_email);
            $event->setAttendees([$att]);
        }


        if ($appt->isVisit()) {
            $conf = new \Google\Service\Calendar\ConferenceData();
            $req = new \Google\Service\Calendar\CreateConferenceRequest();
            $req->setRequestId('alphacrm-' . $appt->id . '-' . Str::random(8));
            $key = new \Google\Service\Calendar\ConferenceSolutionKey();
            $key->setType('hangoutsMeet');
            $req->setConferenceSolutionKey($key);
            $conf->setCreateRequest($req);
            $event->setConferenceData($conf);
        }

        return $event;
    }

    private function composeDescription(Appointment $appt): string
    {
        $parts = [];
        if ($appt->description) $parts[] = $appt->description;
        if ($appt->lead_id) {
            $lead = $appt->lead;
            if ($lead) $parts[] = "Lead: {$lead->name} (ID #{$lead->id})";
        }
        if ($appt->confirmation_token) {
            $base = $this->getCredential('frontend_callback');

            $base = preg_replace('#/(perfil|corretor)\.php.*$#', '', $base ?? '');
            if (!$base) $base = 'https://app.alphacrm.com.br';
            $parts[] = "Link de confirmação para o lead: {$base}/visita.php?t={$appt->confirmation_token}";
        }
        return implode("\n\n", $parts);
    }

    private function updateAppointmentFromEvent(Appointment $appt, $event): void
    {
        $update = [
            'external_event_id'   => $event->getId(),
            'external_event_etag' => $event->getEtag(),
            'last_synced_at'      => now(),
            'last_sync_error'     => null,
        ];


        $conf = $event->getConferenceData();
        if ($conf && $conf->getEntryPoints()) {
            foreach ($conf->getEntryPoints() as $ep) {
                if ($ep->getEntryPointType() === 'video') {
                    $update['meeting_url'] = $ep->getUri();
                    break;
                }
            }
        }

        $appt->update($update);
    }

    private function newRawClient(): \Google\Client
    {
        $client = new \Google\Client();
        $client->setClientId($this->getCredential('client_id'));
        $client->setClientSecret($this->getCredential('client_secret'));
        $client->setRedirectUri($this->getCredential('redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes(self::SCOPES);
        return $client;
    }
}
