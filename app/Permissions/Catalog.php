<?php

namespace App\Permissions;

/**
 * Sprint Cargos — Catálogo central de permissions.
 * ---------------------------------------------------------------
 * Single source of truth — UI lê pra montar a matriz de checkboxes
 * agrupada, e o seeder usa pra sincronizar a tabela `permissions` do
 * Spatie. Adicionou permission nova? Adiciona aqui, roda o seeder.
 *
 * Estrutura: array de grupos. Cada grupo tem:
 *   - key:        slug do grupo (usado em IDs HTML, comparações)
 *   - label:      título visível em PT-BR
 *   - icon:       ícone Lucide pra listar na UI (opcional)
 *   - sensitive:  true se o grupo lida com dados sensíveis (badge ⚠
 *                 destaca pra admin pensar 2x antes de marcar)
 *   - permissions: lista de permissions, cada uma com:
 *      - name:     o slug usado no Spatie (ex: 'leads.view_all')
 *      - label:    texto visível
 *      - hint:     descrição opcional (tooltip)
 *
 * Convenção de nomes:
 *   - {modulo}.view_all   → vê tudo (independente de hierarquia)
 *   - {modulo}.view_team  → vê só do time (subordinados via parent_user_id)
 *   - {modulo}.view_own   → vê só os próprios
 *   - {modulo}.create / .update / .delete → CRUD básico
 *   - {modulo}.{acao_sensivel} → ações específicas (approve, pay, etc)
 */
class Catalog
{
    /**
     * Retorna o catálogo completo agrupado.
     * @return array<int, array{key:string,label:string,icon?:string,sensitive?:bool,permissions:array<int, array{name:string,label:string,hint?:string}>}>
     */
    public static function groups(): array
    {
        return [
            // ============================================================
            // LEADS — fluxo principal do CRM
            // ============================================================
            [
                'key'   => 'leads',
                'label' => 'Leads',
                'icon'  => 'users',
                'permissions' => [
                    ['name' => 'leads.view_all',  'label' => 'Ver todos os leads',          'hint' => 'Vê leads de qualquer corretor (visão admin).'],
                    ['name' => 'leads.view_team', 'label' => 'Ver leads do meu time',       'hint' => 'Vê leads dos subordinados na hierarquia (visão gestor).'],
                    ['name' => 'leads.view_own',  'label' => 'Ver meus próprios leads',     'hint' => 'Vê só os leads atribuídos a si mesmo (visão corretor).'],
                    ['name' => 'leads.create',    'label' => 'Criar lead'],
                    ['name' => 'leads.update_all','label' => 'Editar qualquer lead'],
                    ['name' => 'leads.update_own','label' => 'Editar meus próprios leads'],
                    ['name' => 'leads.delete',    'label' => 'Excluir lead'],
                    ['name' => 'leads.import',    'label' => 'Importar leads em lote'],
                    ['name' => 'leads.export',    'label' => 'Exportar leads (CSV/Excel)'],
                    ['name' => 'leads.assign',    'label' => 'Atribuir lead a outro corretor', 'hint' => 'Tipicamente gestor/admin redistribuindo manualmente.'],
                ],
            ],

            // ============================================================
            // LEAD — ABA DOCUMENTOS (sensível)
            // ============================================================
            [
                'key'       => 'lead_documents',
                'label'     => 'Lead — Aba Documentos',
                'icon'      => 'folder',
                'sensitive' => true,
                'permissions' => [
                    ['name' => 'lead.documents.view',     'label' => 'Ver lista de documentos'],
                    ['name' => 'lead.documents.upload',   'label' => 'Enviar documento'],
                    ['name' => 'lead.documents.download', 'label' => 'Baixar documento',  'hint' => 'Tirar pra download local. Sem essa, só preview no CRM.'],
                    ['name' => 'lead.documents.delete',   'label' => 'Excluir documento (lixeira)'],
                ],
            ],

            // ============================================================
            // LEAD — ABA FINANCEIRO (sensível)
            // ============================================================
            [
                'key'       => 'lead_financial',
                'label'     => 'Lead — Aba Financeiro',
                'icon'      => 'dollar-sign',
                'sensitive' => true,
                'permissions' => [
                    ['name' => 'lead.financial.view', 'label' => 'Ver dados financeiros do lead', 'hint' => 'VGV, comissão estimada, condições de pagamento.'],
                    ['name' => 'lead.financial.edit', 'label' => 'Editar dados financeiros'],
                ],
            ],

            // ============================================================
            // PII / Privacidade (sensível)
            // ============================================================
            [
                'key'       => 'pii',
                'label'     => 'Dados Sensíveis (PII)',
                'icon'      => 'eye-off',
                'sensitive' => true,
                'permissions' => [
                    ['name' => 'pii.reveal',           'label' => 'Revelar dados mascarados', 'hint' => 'Botão "Revelar" em telefone/CPF/email. Gera log auditado.'],
                    ['name' => 'pii.export_unmasked',  'label' => 'Exportar sem máscara'],
                ],
            ],

            // ============================================================
            // EMPREENDIMENTOS
            // ============================================================
            [
                'key'   => 'empreendimentos',
                'label' => 'Empreendimentos',
                'icon'  => 'building-2',
                'permissions' => [
                    ['name' => 'empreendimentos.view',                       'label' => 'Ver empreendimentos'],
                    ['name' => 'empreendimentos.create',                     'label' => 'Criar empreendimento'],
                    ['name' => 'empreendimentos.update',                     'label' => 'Editar empreendimento'],
                    ['name' => 'empreendimentos.delete',                     'label' => 'Excluir empreendimento'],
                    ['name' => 'empreendimentos.field_definitions.manage',   'label' => 'Configurar campos customizados'],
                ],
            ],

            // ============================================================
            // KANBAN
            // ============================================================
            [
                'key'   => 'kanban',
                'label' => 'Kanban',
                'icon'  => 'kanban-square',
                'permissions' => [
                    ['name' => 'kanban.view',     'label' => 'Ver quadro Kanban'],
                    ['name' => 'kanban.move_all', 'label' => 'Mover qualquer card'],
                    ['name' => 'kanban.move_own', 'label' => 'Mover só meus cards'],
                ],
            ],

            // ============================================================
            // AGENDA / TAREFAS
            // ============================================================
            [
                'key'   => 'agenda',
                'label' => 'Agenda & Tarefas',
                'icon'  => 'calendar',
                'permissions' => [
                    ['name' => 'agenda.view_all',   'label' => 'Ver agenda de todos'],
                    ['name' => 'agenda.view_team',  'label' => 'Ver agenda do meu time'],
                    ['name' => 'agenda.view_own',   'label' => 'Ver minha agenda'],
                    ['name' => 'agenda.create',     'label' => 'Criar tarefa/compromisso'],
                    ['name' => 'agenda.update_all', 'label' => 'Editar qualquer tarefa'],
                    ['name' => 'agenda.update_own', 'label' => 'Editar minhas tarefas'],
                    ['name' => 'agenda.delete',     'label' => 'Excluir tarefa'],
                ],
            ],

            // ============================================================
            // COMISSÕES
            // ============================================================
            [
                'key'   => 'commissions',
                'label' => 'Comissões',
                'icon'  => 'banknote',
                'permissions' => [
                    ['name' => 'commissions.view_all',   'label' => 'Ver comissões de todos'],
                    ['name' => 'commissions.view_team',  'label' => 'Ver comissões do meu time'],
                    ['name' => 'commissions.view_own',   'label' => 'Ver minhas comissões'],
                    ['name' => 'commissions.create',     'label' => 'Lançar comissão'],
                    ['name' => 'commissions.update',     'label' => 'Editar comissão'],
                    ['name' => 'commissions.approve',    'label' => 'Aprovar comissão',          'hint' => 'Sensível: passa pra status "aprovada" e desbloqueia pagamento.'],
                    ['name' => 'commissions.pay',        'label' => 'Registrar pagamento',       'hint' => 'Sensível: marca como paga e gera entrada financeira.'],
                ],
            ],

            // ============================================================
            // RELATÓRIOS
            // ============================================================
            [
                'key'   => 'reports',
                'label' => 'Relatórios',
                'icon'  => 'bar-chart-3',
                'permissions' => [
                    ['name' => 'reports.productivity', 'label' => 'Relatório de produtividade'],
                    ['name' => 'reports.financial',    'label' => 'Relatório financeiro'],
                    ['name' => 'reports.export',       'label' => 'Exportar relatórios (PDF/Excel)'],
                ],
            ],

            // ============================================================
            // CHAT
            // ============================================================
            [
                'key'   => 'chat',
                'label' => 'Chat Interno',
                'icon'  => 'message-circle',
                'permissions' => [
                    ['name' => 'chat.use',         'label' => 'Usar o chat'],
                    ['name' => 'chat.audit_mode',  'label' => 'Modo auditoria do chat',   'hint' => 'Sensível: lê DM de qualquer dois usuários. Apenas admin típico.'],
                ],
            ],

            // ============================================================
            // USUÁRIOS / CORRETORES
            // ============================================================
            [
                'key'   => 'users',
                'label' => 'Usuários',
                'icon'  => 'user-cog',
                'permissions' => [
                    ['name' => 'users.view',         'label' => 'Ver lista de usuários'],
                    ['name' => 'users.create',       'label' => 'Criar usuário'],
                    ['name' => 'users.update',       'label' => 'Editar usuário'],
                    ['name' => 'users.delete',       'label' => 'Desativar usuário'],
                    ['name' => 'users.assign_admin', 'label' => 'Promover a admin',         'hint' => 'Sensível: dá acesso total. Apenas admin típico.'],
                ],
            ],

            // ============================================================
            // CONFIGURAÇÕES (admin-only por convenção)
            // ============================================================
            [
                'key'   => 'settings',
                'label' => 'Configurações',
                'icon'  => 'settings',
                'permissions' => [
                    ['name' => 'settings.general',                 'label' => 'Configurações Gerais'],
                    ['name' => 'settings.email',                   'label' => 'Configurações de E-mail'],
                    ['name' => 'settings.pipeline',                'label' => 'Etapas do pipeline'],
                    ['name' => 'settings.empreendimento_fields',  'label' => 'Campos do empreendimento'],
                    ['name' => 'settings.task_colors',             'label' => 'Cores de tarefas'],
                    ['name' => 'settings.roles',                   'label' => 'Cargos e permissões'],
                    ['name' => 'settings.deletion_requests',       'label' => 'Solicitações de exclusão'],
                    ['name' => 'settings.documents_logs',          'label' => 'Logs de download de documentos'],
                    ['name' => 'settings.system',                  'label' => 'Sistema (VPS/monitoramento)'],
                ],
            ],

            // ============================================================
            // NOTIFICAÇÕES
            // ============================================================
            [
                'key'   => 'notifications',
                'label' => 'Notificações',
                'icon'  => 'bell',
                'permissions' => [
                    ['name' => 'notifications.view', 'label' => 'Ver notificações'],
                ],
            ],

            // ============================================================
            // BIBLIOTECA DE MÍDIA — Área do Corretor → aba Biblioteca
            // ============================================================
            // Materiais institucionais (PDFs, fotos, vídeos, contratos
            // modelo) organizados em pastas hierárquicas. Corretor baixa
            // pra usar em redes sociais / divulgação. Admin/gestor sobe
            // e organiza. Permissions granulares pra que clientes
            // diferentes possam ter políticas distintas (ex: gestor sobe
            // mas só admin apaga).
            [
                'key'   => 'media',
                'label' => 'Biblioteca de Mídia',
                'icon'  => 'folder-open',
                'permissions' => [
                    ['name' => 'media.view',          'label' => 'Ver e baixar arquivos',  'hint' => 'Necessária pra abrir a aba Biblioteca'],
                    ['name' => 'media.upload',        'label' => 'Subir arquivos'],
                    ['name' => 'media.create_folder', 'label' => 'Criar pastas'],
                    ['name' => 'media.delete',        'label' => 'Apagar arquivos e pastas', 'hint' => 'Apagar pasta deleta subpastas e arquivos junto'],
                ],
            ],
        ];
    }

    /**
     * Lista flat de TODAS as permission names — usado pelo seeder pra
     * popular a tabela permissions do Spatie.
     * @return list<string>
     */
    public static function allNames(): array
    {
        $names = [];
        foreach (self::groups() as $g) {
            foreach ($g['permissions'] as $p) {
                $names[] = $p['name'];
            }
        }
        return $names;
    }

    /**
     * Sprint Cargos — Permissions LEGADAS que ainda são checadas em
     * vários pontos do backend (middlewares `can:status_required_fields.manage`,
     * policies, etc.) mas que não estão no novo catálogo agrupado.
     *
     * Mantemos elas vivas pra não quebrar nada (admin precisa continuar
     * podendo abrir tela de Etapas, criar usuário, etc.). Não aparecem
     * na UI de Cargos — o admin lida com as equivalentes novas
     * (settings.pipeline, users.create, etc.) e o seeder anexa estas
     * automaticamente baseado no type do cargo.
     *
     * Cleanup futuro: refatorar os 37 pontos do backend pra checar as
     * novas, e remover deste array.
     */
    public static function legacyAll(): array
    {
        return [
            'status_required_fields.manage',
            'custom_fields.manage',
            'users.view',
            'users.manage',
            'leads.view_any',
            'leads.view_own',
            'leads.update_any',
            'leads.update_own',
            'leads.move_any',
            'leads.move_own',
            'kanban.reorder',
            'appointments.view_any',
            'appointments.view_own',
            'appointments.manage_any',
            'appointments.manage_own',
            'empreendimentos.manage',
            'dashboard.view',
            'reports.view',
        ];
    }

    /**
     * Mapeamento de quais legacy permissions cada type system recebe.
     * Espelha o comportamento ANTERIOR pra preservar compat.
     * @return array<string, list<string>>
     */
    public static function legacyDefaultsByType(): array
    {
        $all = self::legacyAll();

        // Corretor antigo só tinha permissions próprias
        $corretor = [
            'leads.view_own',
            'leads.update_own',
            'leads.move_own',
            'appointments.view_own',
            'appointments.manage_own',
            'dashboard.view',
        ];

        // Gestor = tudo das legacy EXCETO users.assign_admin (que não está nas legacy mesmo)
        $gestor = $all;

        return [
            'admin'    => $all,
            'gestor'   => $gestor,
            'corretor' => $corretor,
        ];
    }

    /**
     * Permissions atribuídas por type (admin/gestor/corretor) — define
     * o "kit de fábrica" dos cargos system. Usado pelo seeder.
     *
     * Filosofia:
     *   - admin    → tudo
     *   - gestor   → tudo do admin EXCETO settings.* sensíveis,
     *                users.assign_admin, chat.audit_mode, pii.export_unmasked
     *   - corretor → só os próprios + ver empreendimentos + chat
     *
     * @return array<string, list<string>>  type => permission names
     */
    public static function defaultsByType(): array
    {
        $all = self::allNames();

        // Permissions que o gestor NÃO recebe por padrão (sensíveis ou
        // exclusivas de admin). Pode ser ajustado via UI depois.
        $gestorBlocked = [
            'users.assign_admin',
            'chat.audit_mode',
            'pii.export_unmasked',
            // Configurações são todas admin-only por padrão
            'settings.general',
            'settings.email',
            'settings.pipeline',
            'settings.empreendimento_fields',
            'settings.task_colors',
            'settings.roles',
            'settings.deletion_requests',
            'settings.documents_logs',
            'settings.system',
        ];

        $gestor = array_values(array_diff($all, $gestorBlocked));

        $corretor = [
            'leads.view_own',
            'leads.create',
            'leads.update_own',
            'leads.export',
            'lead.documents.view',
            'lead.documents.upload',
            'lead.financial.view',
            'empreendimentos.view',
            'kanban.view',
            'kanban.move_own',
            'agenda.view_own',
            'agenda.create',
            'agenda.update_own',
            'commissions.view_own',
            'reports.productivity',
            'chat.use',
            'notifications.view',
            // Biblioteca de Mídia: corretor lê+baixa material institucional.
            // Upload/criar pasta/apagar são de gestor/admin (já incluídos
            // nos defaults dos respectivos types via $all e $gestor).
            'media.view',
        ];

        return [
            'admin'    => $all,
            'gestor'   => $gestor,
            'corretor' => $corretor,
        ];
    }
}
