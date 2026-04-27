<?php

namespace App\Permissions;

class Catalog
{

    public static function groups(): array
    {
        return [

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
                    ['name' => 'tipologias.field_definitions.manage',        'label' => 'Configurar campos das tipologias'],
                ],
            ],

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

            [
                'key'   => 'chat',
                'label' => 'Chat Interno',
                'icon'  => 'message-circle',
                'permissions' => [
                    ['name' => 'chat.use',         'label' => 'Usar o chat'],
                    ['name' => 'chat.audit_mode',  'label' => 'Modo auditoria do chat',   'hint' => 'Sensível: lê DM de qualquer dois usuários. Apenas admin típico.'],
                ],
            ],

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

            [
                'key'   => 'settings',
                'label' => 'Configurações',
                'icon'  => 'settings',
                'permissions' => [
                    ['name' => 'settings.general',                 'label' => 'Configurações Gerais'],
                    ['name' => 'settings.email',                   'label' => 'Configurações de E-mail'],
                    ['name' => 'settings.pipeline',                'label' => 'Etapas do pipeline'],
                    ['name' => 'settings.empreendimento_fields',  'label' => 'Campos do empreendimento'],
                    ['name' => 'settings.tipologia_fields',        'label' => 'Campos da tipologia'],
                    ['name' => 'settings.task_colors',             'label' => 'Cores de tarefas'],
                    ['name' => 'settings.roles',                   'label' => 'Cargos e permissões'],
                    ['name' => 'settings.deletion_requests',       'label' => 'Solicitações de exclusão'],
                    ['name' => 'settings.documents_logs',          'label' => 'Logs de download de documentos'],
                    ['name' => 'settings.system',                  'label' => 'Sistema (VPS/monitoramento)'],
                ],
            ],

            [
                'key'   => 'notifications',
                'label' => 'Notificações',
                'icon'  => 'bell',
                'permissions' => [
                    ['name' => 'notifications.view', 'label' => 'Ver notificações'],
                ],
            ],

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

    public static function legacyDefaultsByType(): array
    {
        $all = self::legacyAll();

        $corretor = [
            'leads.view_own',
            'leads.update_own',
            'leads.move_own',
            'appointments.view_own',
            'appointments.manage_own',
            'dashboard.view',
        ];

        $gestor = $all;

        return [
            'admin'    => $all,
            'gestor'   => $gestor,
            'corretor' => $corretor,
        ];
    }

    public static function defaultsByType(): array
    {
        $all = self::allNames();

        $gestorBlocked = [
            'users.assign_admin',
            'chat.audit_mode',
            'pii.export_unmasked',

            'settings.general',
            'settings.email',
            'settings.pipeline',
            'settings.empreendimento_fields',
            'settings.tipologia_fields',
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

            'media.view',
        ];

        return [
            'admin'    => $all,
            'gestor'   => $gestor,
            'corretor' => $corretor,
        ];
    }
}
