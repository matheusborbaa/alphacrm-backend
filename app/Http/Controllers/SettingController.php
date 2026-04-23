<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Configurações globais do sistema (chave/valor).
 *
 * Leitura: qualquer usuário autenticado. Páginas da UI leem flags como
 * `watermark_enabled` pra decidir se ligam/desligam recursos.
 *
 * Escrita: só admin (verificado aqui com role check — não via middleware
 * pra manter consistência com o padrão do LeadDocumentController).
 *
 * Whitelist: `ALLOWED_KEYS` limita o que pode ser gravado — evita que
 * alguém crie chaves arbitrárias pela API.
 */
class SettingController extends Controller
{
    /**
     * Chaves conhecidas pelo sistema. Não aceita nada fora disso no set().
     * Cada entrada diz o tipo esperado (pra validação simples) e o default.
     */
    private const ALLOWED_KEYS = [
        // Privacidade
        'watermark_enabled'    => ['type' => 'bool', 'default' => true],
        'watermark_intensity'  => [
            'type'    => 'enum',
            'default' => 'sutil',
            'options' => ['sutil', 'medio', 'forte'],
        ],

        // Retenção de documentos
        // Janela em dias entre soft-delete e hard-delete pelo job PurgeExpiredDocuments.
        'doc_retention_days'   => [
            'type'    => 'int',
            'default' => 7,
            'min'     => 1,
            'max'     => 365,
        ],
        // Se false, corretor clica em 'excluir' e o doc já vai pra lixeira
        // sem passar pela aprovação do admin (pula o fluxo request-deletion).
        'doc_deletion_requires_approval' => [
            'type'    => 'bool',
            'default' => true,
        ],

        // =================== COOLDOWN PÓS-LEAD ==========================
        // Quando o rodízio entrega um lead pra um corretor, se esse toggle
        // estiver ligado, o corretor vira 'ocupado' automaticamente por
        // lead_cooldown_minutes. Durante esse período não recebe leads.
        'lead_cooldown_enabled' => [
            'type'    => 'bool',
            'default' => false,
        ],
        'lead_cooldown_minutes' => [
            'type'    => 'int',
            'default' => 2,
            'min'     => 0,
            'max'     => 120,
        ],

        // =================== SLA DE ATENDIMENTO =========================
        // Prazo em minutos pra corretor fazer primeira interação após
        // receber o lead. 0 ou disabled = não grava sla_deadline_at.
        'lead_sla_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],
        'lead_sla_minutes' => [
            'type'    => 'int',
            'default' => 15,
            'min'     => 0,
            'max'     => 1440,
        ],

        // Quando SLA expira, se ligado o job reatribui o lead (tira do
        // corretor atual e joga na fila pro próximo disponível). Se
        // desligado, o lead só é marcado 'expired' e continua com o
        // corretor — o gestor decide o que fazer. Default: true.
        'lead_sla_reassign_on_breach' => [
            'type'    => 'bool',
            'default' => true,
        ],

        // =================== STATUS INICIAL DO RODÍZIO ==================
        // Quando o lead cai no rodízio, muda pra essa etapa (ex:
        // "Aguardando atendimento"). null = não mexe no status atual.
        'lead_first_status_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],
        // Subetapa opcional pra acompanhar lead_first_status_id.
        // null = não seta subetapa (ou a etapa escolhida não tem subetapa).
        'lead_first_substatus_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],

        // =================== PÓS PRIMEIRO CONTATO =======================
        // Quando o corretor clica em "Registrar primeiro contato" no lead,
        // o lead muda pra essa etapa + subetapa. null em qualquer das duas
        // = não mexe naquele campo. Útil pra fluxo tipo:
        //   Aguardando atendimento → Em atendimento / Qualificação
        'lead_after_first_contact_status_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],
        'lead_after_first_contact_substatus_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],

        // =================== MÓDULO DE CHAT =============================
        // Liga/desliga o chat interno globalmente. Quando OFF: sidebar esconde
        // o item, deep-link /chat.php redireciona pro dashboard e os
        // endpoints /conversations e /messages* retornam 403. Default true
        // pra manter o comportamento existente.
        'chat_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],

        // =================== ALERTAS DE CAPACIDADE DO SERVIDOR ==========
        // Monitoramento automático de disco/RAM disparado pelo comando
        // agendado `servidor:check-capacity` (routes/console.php, hourly).
        // Quando disco ou RAM cruza o threshold, todos os admins ativos
        // recebem notificação DENTRO do CRM (sino + banner no dashboard)
        // pra acionarem o upgrade do servidor. Sem e-mail — contido no
        // próprio sistema. Dedup interno: depois de disparado, só
        // re-alerta depois de 24h enquanto o problema persistir (evita
        // spam do sino).
        'server_alert_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],
        // Threshold de disco em percentual. Default 75 — deixa margem pra
        // o admin agendar upgrade antes do disco estourar (backups, logs,
        // uploads não param de crescer).
        'server_alert_disk_threshold' => [
            'type'    => 'int',
            'default' => 75,
            'min'     => 50,
            'max'     => 99,
        ],
        // Threshold de RAM. Default 90 — mais apertado porque RAM
        // saturada degrada performance rapidamente (swap, OOM killer).
        'server_alert_ram_threshold' => [
            'type'    => 'int',
            'default' => 90,
            'min'     => 50,
            'max'     => 99,
        ],
    ];

    /** Lista TODAS as configurações (chave => valor). Só chaves conhecidas. */
    public function index()
    {
        $out = [];
        foreach (self::ALLOWED_KEYS as $key => $meta) {
            $out[$key] = Setting::get($key, $meta['default']);
        }
        return response()->json($out);
    }

    /** Lê UMA configuração pelo nome. */
    public function show(string $key)
    {
        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }
        $meta  = self::ALLOWED_KEYS[$key];
        $value = Setting::get($key, $meta['default']);
        return response()->json(['key' => $key, 'value' => $value]);
    }

    /**
     * Grava UMA configuração. Body: { "value": <...> }.
     * Só admin. Valor é validado contra o tipo esperado.
     */
    public function update(Request $request, string $key)
    {
        $this->ensureAdmin();

        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }

        $meta = self::ALLOWED_KEYS[$key];
        $raw  = $request->input('value');

        $value = $this->coerce($raw, $meta['type'], $meta);

        // int_or_null aceita null explicitamente ("desligado"), então não
        // falha quando $value === null — nos demais tipos, null = erro.
        $allowNull = in_array($meta['type'], ['int_or_null', 'mixed'], true);
        if ($value === null && $raw !== null && !$allowNull) {
            return response()->json([
                'message' => "Valor inválido pra '{$key}' (esperado {$meta['type']}).",
            ], 422);
        }

        // Clamp pra tipos com range (int com min/max)
        if (in_array($meta['type'], ['int', 'int_or_null'], true) && $value !== null) {
            if (isset($meta['min']) && $value < $meta['min']) {
                return response()->json([
                    'message' => "Valor mínimo pra '{$key}' é {$meta['min']}.",
                ], 422);
            }
            if (isset($meta['max']) && $value > $meta['max']) {
                return response()->json([
                    'message' => "Valor máximo pra '{$key}' é {$meta['max']}.",
                ], 422);
            }
        }

        // Enum: garante que o valor caiu numa das opções permitidas
        if ($meta['type'] === 'enum') {
            if (!in_array($value, $meta['options'], true)) {
                return response()->json([
                    'message' => "Valor inválido. Use: " . implode(', ', $meta['options']) . '.',
                ], 422);
            }
        }

        Setting::set($key, $value);

        return response()->json(['key' => $key, 'value' => $value]);
    }

    /* ==============================================================
     * HELPERS
     * ============================================================== */

    /** Converte entrada crua pro tipo da chave. Null = erro. */
    private function coerce(mixed $raw, string $type, array $meta = []): mixed
    {
        return match ($type) {
            'bool'   => is_bool($raw) ? $raw
                        : (in_array($raw, [1, '1', 'true', 'on', 'yes'], true) ? true
                        : (in_array($raw, [0, '0', 'false', 'off', 'no', '', null], true) ? false
                        : null)),
            'int'    => is_numeric($raw) ? (int) $raw : null,
            // int_or_null: '', null, 'null' e 0/negativo não-numérico viram null ("desligado");
            // números válidos (incluindo "0") viram int. Usado por lead_first_status_id.
            'int_or_null' => ($raw === null || $raw === '' || $raw === 'null')
                ? null
                : (is_numeric($raw) ? (int) $raw : null),
            'string' => is_string($raw) ? $raw : null,
            'enum'   => is_string($raw) ? $raw : null,
            default  => $raw, // 'mixed' — aceita tudo
        };
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        $role = strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }
}
