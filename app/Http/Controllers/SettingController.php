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

        // =================== GATILHO DE COMISSÃO (Sprint 3.7e) ==========
        // Quais status e/ou substatus disparam a criação automática da
        // comissão (draft) via LeadObserver. Arrays de IDs — a comissão é
        // criada quando o lead ENTRA em qualquer um dos status listados
        // OU quando entra em qualquer um dos substatus listados.
        //
        // Default vazio: mantém o comportamento legado (nome do status ==
        // "Vendido"). Assim instalações já rodando não precisam migrar
        // nada — e admin pode desligar a regra "Vendido" escolhendo outros
        // gatilhos aqui sem mexer na tabela de status.
        'commission_trigger_status_ids' => [
            'type'    => 'int_array',
            'default' => [],
        ],
        'commission_trigger_substatus_ids' => [
            'type'    => 'int_array',
            'default' => [],
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

        // =================== SESSÕES SIMULTÂNEAS (Sprint 3.0a) ==========
        // Quantidade máxima de tokens Sanctum vivos por user. No login,
        // AuthController conta os tokens; se atingiu, devolve 409 com a
        // lista pra o user escolher qual encerrar.
        'max_concurrent_sessions' => [
            'type'    => 'int',
            'default' => 2,
            'min'     => 1,
            'max'     => 10,
        ],

        // Minutos de ociosidade antes da próxima ação sensível pedir senha
        // de novo. 0 desliga. Checado pelo middleware fresh-auth — se passou
        // do threshold, devolve 423 e o frontend abre modal preservando o
        // estado atual da página (formulários, uploads etc).
        'password_confirm_idle_minutes' => [
            'type'    => 'int',
            'default' => 30,
            'min'     => 0,
            'max'     => 1440,
        ],

        // =================== ALERTAS DE CAPACIDADE DO SERVIDOR ==========
        // Thresholds fixos e sempre-ativos (75% disco, 90% RAM) — a UI
        // não expõe configuração pra manter a operação previsível. Ver
        // CheckServerCapacity e VpsStatusController::capacityAlerts, que
        // carregam os limites direto como constantes. Dedup interno de
        // 24h continua usando as chaves `_server_alert_state_*` e
        // `_server_alert_last_notify_*`, que ficam fora desse whitelist
        // porque são estado interno (prefixadas com "_") e não passam
        // pelo endpoint de escrita.
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
            // int_array: aceita array de ints OU string CSV ("1,2,3").
            // null/'' viram []. Ids inválidos/duplicados são descartados
            // silenciosamente — o resultado final é sempre um array de
            // ints distintos e positivos, reindexado.
            'int_array' => $this->coerceIntArray($raw),
            'string' => is_string($raw) ? $raw : null,
            'enum'   => is_string($raw) ? $raw : null,
            default  => $raw, // 'mixed' — aceita tudo
        };
    }

    /**
     * Helper do coerce('int_array'). Normaliza qualquer entrada em um
     * array de ints únicos e positivos. Nunca devolve null — o pior caso
     * é um array vazio (igual ao default da whitelist).
     */
    private function coerceIntArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') return [];

        // CSV vindo de input text: "1, 2, 3"
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $v) {
            if (!is_numeric($v)) continue;
            $i = (int) $v;
            if ($i <= 0) continue;
            $out[$i] = true; // dedup via chave
        }
        return array_values(array_map('intval', array_keys($out)));
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
